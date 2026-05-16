<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Employee;
use App\Models\EstimationVersion;
use App\Models\Project;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Loads the reference estimation_template.xlsx (5 sheets, hand-authored by the
 * agency) and replaces only data cells / data rows with the live values from
 * an EstimationVersion. Sheet titles, merged ranges, column widths, fills,
 * borders, fonts, and the cross-sheet summary formulas all come from the
 * template — never recreated in PHP.
 *
 * The exact cell layout of the reference template is captured in the
 * constants below. If the template is ever re-authored, these are the only
 * positions to revisit.
 */
class EstimationXlsxService
{
    // Template is a code-tracked asset, stored outside the local disk root
    // (which Laravel scopes to storage/app/private/). Reading it via
    // storage_path() instead of Storage::disk('local') keeps the layout
    // explicit: templates/ holds blueprints, the local disk holds output.
    private const TEMPLATE_PATH = 'app/templates/estimation_template.xlsx';

    // Sheet 0 — 全体工数 (overall manhour summary). Data cells the writer
    // mutates; everything else (titles, the cross-sheet formula at C4, the
    // SUM formulas at C6/C7/C8) is preserved.
    private const SHEET1_ROUGH_ESTIMATE = 'C2';

    private const SHEET1_REQUIREMENT_STUDY = 'C3';

    // Sheet 1 — Web_Function List. Data rows start at 2.
    private const SHEET2_FIRST_DATA_ROW = 2;

    private const SHEET2_LAST_TEMPLATE_ROW = 70;

    private const SHEET2_COL_NO = 'B';

    private const SHEET2_COL_FUNCTION_ID = 'C';

    private const SHEET2_COL_NAME = 'D';

    private const SHEET2_COL_EXPLANATION = 'E';

    private const SHEET2_COL_CATEGORY = 'F';

    // Sheet 2 — Web_Manhour_Detail. Rows 1–4 (group labels + multiplier ratios
    // + header) are preserved. Data rows 5–74 (70 rows) are cleared and
    // re-populated; rows 75–80 (summary + team totals) keep their template
    // formulas, which reference D5:D74 et al. If features exceed 70, this
    // service truncates and logs a warning — extending the SUM ranges is a
    // v2 concern.
    private const SHEET3_FIRST_DATA_ROW = 5;

    private const SHEET3_LAST_DATA_ROW = 74;

    private const SHEET3_COL_FUNCTION_ID = 'A';

    private const SHEET3_COL_NAME = 'B';

    private const SHEET3_COL_STATUS = 'C';

    private const SHEET3_COL_DEV_HOURS = 'D';

    // Phase columns E..AF receive formulas =D{row}*{multiplier}; the
    // multipliers live in row 2 of the template (E2=0.1, F2=0.15, …).
    // The writer reads them at runtime so the template can be re-tuned
    // without redeploying. AG = Total (SUM(D..AF)).
    private const SHEET3_FIRST_PHASE_COL = 'E';

    private const SHEET3_LAST_PHASE_COL = 'AF';

    private const SHEET3_TOTAL_COL = 'AG';

    private const SHEET3_MULTIPLIER_ROW = 2;

    // Sheet 3 — Milestone. Phase labels in column A are preserved. The
    // template carries a fixed 4-month / 5-month-wide grid (D..X) of
    // week columns; this v1 leaves that grid as-is and only refreshes
    // the month-label row when the deal has a meaningful start_date /
    // timeline. Re-laying-out the columns dynamically is deferred.
    private const SHEET4_MONTH_LABEL_ROW = 2;

    private const SHEET4_MONTH_LABEL_COLS = ['D', 'H', 'M', 'Q', 'U'];

    // Sheet 4 — 人の山積 (resource loading).
    private const SHEET5_YEAR_CELL = 'D4';

    private const SHEET5_MONTHS_ROW = 5;

    private const SHEET5_MONTH_COLS = ['D', 'E', 'F', 'G'];

    private const SHEET5_FIRST_MEMBER_ROW = 6;

    private const SHEET5_LAST_MEMBER_ROW = 11;

    private const SHEET5_TOTAL_ROW = 12;

    private const SHEET5_MEMBER_NAME_COL = 'B';

    private const SHEET5_SUBTOTAL_COL = 'H';

    /**
     * Generate the XLSX for a specific version and persist the storage path.
     * Returns the relative storage path (Storage disk = local).
     */
    public function generateAndStore(EstimationVersion $version): string
    {
        // Bump memory ceiling — large estimations with the formula cache
        // can push past the default. Cheap; reverts at request end.
        @ini_set('memory_limit', '512M');

        $deal = Deal::with(['estimation_resources', 'hard_assignments'])->findOrFail($version->deal_id);
        $project = Project::where('tenant_id', $deal->tenant_id)
            ->whereHas('contract', fn ($q) => $q->where('deal_id', $deal->id))
            ->first();

        $spreadsheet = $this->loadTemplate();

        $this->fillSheetSummary($spreadsheet, $deal, $version);
        $this->fillSheetFunctionList($spreadsheet, $version);
        $this->fillSheetManhourDetail($spreadsheet, $version);
        $this->fillSheetMilestone($spreadsheet, $deal);
        $this->fillSheetTeamStack($spreadsheet, $deal, $version);

        $spreadsheet->getCalculationEngine()->clearCalculationCache();

        $path = $this->storagePathFor($deal, $project, $version);
        $this->writeSpreadsheet($spreadsheet, $path);

        // Use a direct DB update because the model has $timestamps = false
        // and we don't want to touch any other field.
        DB::table('estimation_versions')->where('id', $version->id)->update(['xlsx_path' => $path]);
        $version->xlsx_path = $path;

        return $path;
    }

    /**
     * Move every version's file for a won deal from deals/{id}/ to
     * projects/{number}/. Renames each file to the post-win naming scheme.
     * Idempotent: re-running after partial success skips files already moved.
     */
    public function migrateToProject(Deal $deal, Project $project): void
    {
        $versions = EstimationVersion::where('deal_id', $deal->id)->get();

        foreach ($versions as $version) {
            try {
                $newPath = $this->projectScopedPath($project, $version);
                $oldPath = $version->xlsx_path;

                // Skip if already at the project path.
                if ($oldPath === $newPath && Storage::disk('local')->exists($newPath)) {
                    continue;
                }

                if ($oldPath && Storage::disk('local')->exists($oldPath)) {
                    // Make sure the destination dir exists; Storage::move
                    // does NOT create parents on some drivers.
                    $destDir = dirname($newPath);
                    if (! Storage::disk('local')->exists($destDir)) {
                        Storage::disk('local')->makeDirectory($destDir);
                    }
                    Storage::disk('local')->move($oldPath, $newPath);
                } else {
                    // Source missing — regenerate at the new path. This also
                    // covers the lazy case where xlsx_path was null.
                    $this->generateAndStore($version);
                    // generateAndStore picked the deal-scoped path because
                    // the project link wasn't refreshed yet on $version's
                    // own deal; force a regen at the project path.
                    Storage::disk('local')->move($version->xlsx_path, $newPath);
                }

                DB::table('estimation_versions')->where('id', $version->id)->update(['xlsx_path' => $newPath]);
            } catch (Throwable $e) {
                Log::warning('EstimationXlsx: failed to migrate version file to project', [
                    'version_id' => $version->id,
                    'deal_id' => $deal->id,
                    'project_id' => $project->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function loadTemplate(): Spreadsheet
    {
        $abs = storage_path(self::TEMPLATE_PATH);
        if (! is_file($abs)) {
            throw new \RuntimeException('Estimation template not found at '.self::TEMPLATE_PATH);
        }

        return IOFactory::load($abs);
    }

    private function fillSheetSummary(Spreadsheet $ss, Deal $deal, EstimationVersion $version): void
    {
        $sheet = $ss->getSheetByName('全体工数') ?? $ss->getSheet(0);

        // The template ships with sensible defaults (48 / 80). We only
        // override when the deal carries a real signal — preserves the
        // agency's "rough estimate" placeholders for skinny deals.
        if (! empty($deal->workload_hours)) {
            // Spread the workload across the two summary lines proportionally
            // to template defaults (~37.5% rough estimate, ~62.5% requirement
            // study). This is a heuristic — if the AI pipeline supplies its
            // own sheet1_summary, the EstimationVersion.resources payload
            // can carry override values which take precedence below.
            $sheet->setCellValue(self::SHEET1_ROUGH_ESTIMATE, (float) round($deal->workload_hours * 0.375));
            $sheet->setCellValue(self::SHEET1_REQUIREMENT_STUDY, (float) round($deal->workload_hours * 0.625));
        }

        $summary = $version->resources['_sheet1_summary'] ?? null;
        if (is_array($summary)) {
            if (isset($summary['rough_estimate_hours'])) {
                $sheet->setCellValue(self::SHEET1_ROUGH_ESTIMATE, (float) $summary['rough_estimate_hours']);
            }
            if (isset($summary['requirement_study_hours'])) {
                $sheet->setCellValue(self::SHEET1_REQUIREMENT_STUDY, (float) $summary['requirement_study_hours']);
            }
        }
    }

    private function fillSheetFunctionList(Spreadsheet $ss, EstimationVersion $version): void
    {
        $sheet = $ss->getSheetByName('Web_Function List');
        if (! $sheet) {
            return;
        }

        $features = $this->extractFeatures($version);

        // Clear the template's sample rows. We clear values only (not styles)
        // so column widths, borders, and the merged cells in column F stay
        // intact. Range from first data row to the template's last row.
        for ($r = self::SHEET2_FIRST_DATA_ROW; $r <= self::SHEET2_LAST_TEMPLATE_ROW; $r++) {
            foreach ([self::SHEET2_COL_NO, self::SHEET2_COL_FUNCTION_ID, self::SHEET2_COL_NAME, self::SHEET2_COL_EXPLANATION, self::SHEET2_COL_CATEGORY] as $col) {
                $sheet->setCellValue($col.$r, null);
            }
        }

        $no = 1;
        foreach ($features as $i => $f) {
            $row = self::SHEET2_FIRST_DATA_ROW + $i;
            $sheet->setCellValue(self::SHEET2_COL_NO.$row, $no);
            $sheet->setCellValue(self::SHEET2_COL_FUNCTION_ID.$row, $f['function_id']);
            $sheet->setCellValue(self::SHEET2_COL_NAME.$row, $f['name']);
            $sheet->setCellValue(self::SHEET2_COL_EXPLANATION.$row, $f['explanation']);
            $sheet->setCellValue(self::SHEET2_COL_CATEGORY.$row, $f['category']);
            $no++;
        }
    }

    private function fillSheetManhourDetail(Spreadsheet $ss, EstimationVersion $version): void
    {
        $sheet = $ss->getSheetByName('Web_Manhour_Detail');
        if (! $sheet) {
            return;
        }

        $features = $this->extractFeatures($version);

        $capacity = self::SHEET3_LAST_DATA_ROW - self::SHEET3_FIRST_DATA_ROW + 1;
        if (count($features) > $capacity) {
            Log::warning('EstimationXlsx: feature count exceeds template capacity, truncating', [
                'version_id' => $version->id,
                'features' => count($features),
                'capacity' => $capacity,
            ]);
            $features = array_slice($features, 0, $capacity);
        }

        $phaseCols = $this->columnRange(self::SHEET3_FIRST_PHASE_COL, self::SHEET3_LAST_PHASE_COL);

        // Read multiplier ratios from row 2 of the template. Each phase column
        // carries its own ratio (E2=0.1 = code review, F2=0.15 = prototype,
        // etc.). Cache them so we can write =D{r}*{ratio} per feature row.
        $multipliers = [];
        foreach ($phaseCols as $col) {
            $multipliers[$col] = $sheet->getCell($col.self::SHEET3_MULTIPLIER_ROW)->getValue();
        }

        // Clear every data row first — values AND formulas. Otherwise stale
        // formula cells from the template's sample data leak into output for
        // any row beyond the new feature count.
        for ($r = self::SHEET3_FIRST_DATA_ROW; $r <= self::SHEET3_LAST_DATA_ROW; $r++) {
            $sheet->setCellValue(self::SHEET3_COL_FUNCTION_ID.$r, null);
            $sheet->setCellValue(self::SHEET3_COL_NAME.$r, null);
            $sheet->setCellValue(self::SHEET3_COL_STATUS.$r, null);
            $sheet->setCellValue(self::SHEET3_COL_DEV_HOURS.$r, 0); // SUMs reference D5:D74 — keep numeric zeros
            foreach ($phaseCols as $col) {
                $sheet->setCellValue($col.$r, 0);
            }
            $sheet->setCellValue(self::SHEET3_TOTAL_COL.$r, 0);
        }

        foreach ($features as $i => $f) {
            $row = self::SHEET3_FIRST_DATA_ROW + $i;
            $sheet->setCellValue(self::SHEET3_COL_FUNCTION_ID.$row, $f['function_id']);
            $sheet->setCellValue(self::SHEET3_COL_NAME.$row, $f['name']);
            $sheet->setCellValue(self::SHEET3_COL_STATUS.$row, $f['status'] ?? '');
            $sheet->setCellValue(self::SHEET3_COL_DEV_HOURS.$row, (float) $f['dev_hours']);

            // Phase columns: =$D{row}*{multiplier-cell}. Anchor on the
            // multiplier ROW so each feature row picks up the same ratio
            // (E$2, F$2, …). Some template columns are flagged as
            // manual-input (e.g. AD2 = "入力列"); for those we leave 0 so
            // the user can type real hours in, and we avoid the #VALUE!
            // cascade from multiplying numbers by text.
            foreach ($phaseCols as $col) {
                $m = $multipliers[$col];
                if (is_numeric($m)) {
                    $sheet->setCellValue($col.$row, '='.self::SHEET3_COL_DEV_HOURS.$row.'*'.$col.'$'.self::SHEET3_MULTIPLIER_ROW);
                } else {
                    $sheet->setCellValue($col.$row, 0);
                }
            }

            $sheet->setCellValue(
                self::SHEET3_TOTAL_COL.$row,
                '=SUM('.self::SHEET3_COL_DEV_HOURS.$row.':'.self::SHEET3_LAST_PHASE_COL.$row.')',
            );
        }
    }

    private function fillSheetMilestone(Spreadsheet $ss, Deal $deal): void
    {
        $sheet = $ss->getSheetByName('Milestone');
        if (! $sheet) {
            return;
        }

        // The template carries Oct/Nov/Dec/Jan/Feb as month labels — these are
        // placeholders. Replace them with month abbreviations starting from
        // the deal's expected_close_date (or today as fallback).
        $start = $deal->expected_close_date
            ? Carbon::parse($deal->expected_close_date)->startOfMonth()
            : now()->startOfMonth();

        foreach (self::SHEET4_MONTH_LABEL_COLS as $i => $col) {
            $sheet->setCellValue($col.self::SHEET4_MONTH_LABEL_ROW, $start->copy()->addMonths($i)->format('M'));
        }
    }

    private function fillSheetTeamStack(Spreadsheet $ss, Deal $deal, EstimationVersion $version): void
    {
        $sheet = $ss->getSheetByName('人の山積');
        if (! $sheet) {
            return;
        }

        // Year cell — pull from expected_close_date when present.
        $year = $deal->expected_close_date
            ? Carbon::parse($deal->expected_close_date)->year
            : now()->year;
        $sheet->setCellValue(self::SHEET5_YEAR_CELL, (string) $year);

        // Refresh month numbers in row 5. Template ships with 10/11/12/1
        // (Oct-Jan); regenerate to match the new year/start.
        $start = $deal->expected_close_date
            ? Carbon::parse($deal->expected_close_date)->startOfMonth()
            : now()->startOfMonth();
        foreach (self::SHEET5_MONTH_COLS as $i => $col) {
            $sheet->setCellValue($col.self::SHEET5_MONTHS_ROW, (int) $start->copy()->addMonths($i)->format('n'));
        }

        $members = $this->extractTeamMembers($deal, $version);

        $capacity = self::SHEET5_LAST_MEMBER_ROW - self::SHEET5_FIRST_MEMBER_ROW + 1;
        if (count($members) > $capacity) {
            Log::warning('EstimationXlsx: team member count exceeds template capacity, truncating', [
                'version_id' => $version->id,
                'members' => count($members),
                'capacity' => $capacity,
            ]);
            $members = array_slice($members, 0, $capacity);
        }

        // Reset all member rows to a clean baseline (empty name, zero in each
        // month column, SubTotal formula). The data loop below overwrites
        // these cells for any member we actually have. Rows past the member
        // count stay zeroed so the SUM at row 12 still computes correctly.
        $first = self::SHEET5_MONTH_COLS[0];
        $last = self::SHEET5_MONTH_COLS[array_key_last(self::SHEET5_MONTH_COLS)];
        for ($r = self::SHEET5_FIRST_MEMBER_ROW; $r <= self::SHEET5_LAST_MEMBER_ROW; $r++) {
            $sheet->setCellValue(self::SHEET5_MEMBER_NAME_COL.$r, null);
            foreach (self::SHEET5_MONTH_COLS as $col) {
                $sheet->setCellValue($col.$r, 0);
            }
            $sheet->setCellValue(self::SHEET5_SUBTOTAL_COL.$r, "=SUM($first$r:$last$r)");
        }

        foreach ($members as $i => $m) {
            $row = self::SHEET5_FIRST_MEMBER_ROW + $i;
            $sheet->setCellValue(self::SHEET5_MEMBER_NAME_COL.$row, $m['name']);
            // Push the monthly allocation. AI-sourced rows carry an array
            // aligned to SHEET5_MONTH_COLS; hard_assignment / role-fallback
            // rows leave this empty so the cells stay 0 for manual entry.
            $alloc = $m['monthly_allocation'] ?? [];
            foreach (self::SHEET5_MONTH_COLS as $idx => $col) {
                $val = (float) ($alloc[$idx] ?? 0);
                if ($val !== 0.0) {
                    $sheet->setCellValue($col.$row, $val);
                }
            }
        }
    }

    /**
     * Extract feature rows from the version snapshot. EstimationVersion stores
     * a JSONB array of resources; each row carries a feature_name and hours.
     * function_id is synthesised as F001/F002/… in insertion order.
     */
    private function extractFeatures(EstimationVersion $version): array
    {
        $out = [];
        foreach (($version->resources ?? []) as $i => $r) {
            // Skip per-version metadata blobs (Sheet 1 summary, Sheet 5
            // team stack). They share the resources JSONB but aren't
            // feature rows. Any underscore-prefixed key is a sentinel.
            if (isset($r['_sheet1_summary']) || isset($r['_sheet5_team_stack'])) {
                continue;
            }
            $name = $r['feature_name'] ?? $r['featureName'] ?? '';
            if ($name === '') {
                continue;
            }
            $out[] = [
                'function_id' => $r['function_id'] ?? sprintf('F%03d', $i + 1),
                'name' => $name,
                'explanation' => $r['explanation'] ?? '',
                'category' => $r['category'] ?? 'Web',
                'status' => $r['status'] ?? '',
                'dev_hours' => (float) ($r['hours'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Team members for Sheet 5. Source precedence:
     *   1. AI-generated _sheet5_team_stack sentinel — carries role title +
     *      per-month allocation; written into D6:G11.
     *   2. Per-employee aggregate of the version's resources — when any
     *      resource row names an employee, sum their hours and spread across
     *      the deal's timeline (or 4 months, whichever is shorter).
     *   3. DealHardAssignment fallback — spreads allocated_hours / timeline.
     *   4. Role-grouped aggregate — sums hours per role from resources.
     *
     * Each returned row is `['name' => string, 'monthly_allocation' => array<float>]`.
     * The allocation array is aligned with SHEET5_MONTH_COLS (4 months); missing
     * entries are treated as 0 by the caller.
     */
    private function extractTeamMembers(Deal $deal, EstimationVersion $version): array
    {
        // (1) Sentinel from AI generation
        foreach (($version->resources ?? []) as $r) {
            if (isset($r['_sheet5_team_stack']) && is_array($r['_sheet5_team_stack'])) {
                $out = [];
                foreach ($r['_sheet5_team_stack'] as $entry) {
                    $alloc = $entry['monthly_allocation'] ?? [];
                    $out[] = [
                        'name' => (string) ($entry['role'] ?? 'Role'),
                        'monthly_allocation' => is_array($alloc) ? array_map('floatval', $alloc) : [],
                    ];
                }
                if (! empty($out)) {
                    return $out;
                }
            }
        }

        $visibleMonths = count(self::SHEET5_MONTH_COLS);
        $timeline = max(1, (int) ($deal->timeline_months ?: $visibleMonths));
        $spread = min($visibleMonths, $timeline);

        // (2) Per-employee aggregate from version resources. Skips sentinel rows.
        $hoursByEmployee = [];
        foreach (($version->resources ?? []) as $r) {
            if ($this->isSentinelRow($r)) {
                continue;
            }
            $empId = $r['employee_id'] ?? $r['employeeId'] ?? null;
            if (! $empId) {
                continue;
            }
            $hoursByEmployee[$empId] = ($hoursByEmployee[$empId] ?? 0) + (float) ($r['hours'] ?? 0);
        }
        if (! empty($hoursByEmployee)) {
            $employees = Employee::whereIn('id', array_keys($hoursByEmployee))->get()->keyBy('id');
            $out = [];
            foreach ($hoursByEmployee as $empId => $totalHours) {
                $perMonth = $totalHours / $timeline;
                $alloc = array_fill(0, $visibleMonths, 0.0);
                for ($i = 0; $i < $spread; $i++) {
                    $alloc[$i] = round($perMonth, 1);
                }
                $out[] = [
                    'name' => $employees[$empId]?->name ?? 'Employee',
                    'monthly_allocation' => $alloc,
                ];
            }

            return $out;
        }

        // (3) Hard-assignment fallback: spread each employee's allocated_hours
        if ($deal->relationLoaded('hard_assignments') && $deal->hard_assignments->isNotEmpty()) {
            $out = [];
            foreach ($deal->hard_assignments as $ha) {
                $employee = Employee::find($ha->employee_id);
                $perMonth = ((float) $ha->allocated_hours) / $timeline;
                $alloc = array_fill(0, $visibleMonths, 0.0);
                for ($i = 0; $i < $spread; $i++) {
                    $alloc[$i] = round($perMonth, 1);
                }
                $out[] = [
                    'name' => $employee?->name ?? 'Employee',
                    'monthly_allocation' => $alloc,
                ];
            }

            return $out;
        }

        // (4) Role aggregate — sum hours per role across all features
        $hoursByRoleId = [];
        foreach (($version->resources ?? []) as $r) {
            if ($this->isSentinelRow($r)) {
                continue;
            }
            $roleId = $r['role_id'] ?? $r['roleId'] ?? null;
            if (! $roleId) {
                continue;
            }
            $hoursByRoleId[$roleId] = ($hoursByRoleId[$roleId] ?? 0) + (float) ($r['hours'] ?? 0);
        }
        if (empty($hoursByRoleId)) {
            return [];
        }
        $roles = Role::whereIn('id', array_keys($hoursByRoleId))->get()->keyBy('id');
        $out = [];
        foreach ($hoursByRoleId as $roleId => $totalHours) {
            $perMonth = $totalHours / $timeline;
            $alloc = array_fill(0, $visibleMonths, 0.0);
            for ($i = 0; $i < $spread; $i++) {
                $alloc[$i] = round($perMonth, 1);
            }
            $out[] = [
                'name' => $roles[$roleId]?->title ?? 'Role',
                'monthly_allocation' => $alloc,
            ];
        }

        return $out;
    }

    /**
     * Underscore-prefixed sentinel rows (_sheet1_summary, _sheet5_team_stack)
     * share the resources JSONB with real feature rows — must be skipped
     * before summing hours per employee/role.
     */
    private function isSentinelRow(mixed $row): bool
    {
        if (! is_array($row)) {
            return false;
        }
        foreach (array_keys($row) as $k) {
            if (is_string($k) && str_starts_with($k, '_')) {
                return true;
            }
        }

        return false;
    }

    private function storagePathFor(Deal $deal, ?Project $project, EstimationVersion $version): string
    {
        if ($project) {
            return $this->projectScopedPath($project, $version);
        }

        return "deals/{$deal->id}/estimation_v{$version->version_number}.xlsx";
    }

    private function projectScopedPath(Project $project, EstimationVersion $version): string
    {
        return "projects/{$project->project_number}/{$project->project_number}_estimation_v{$version->version_number}.xlsx";
    }

    private function writeSpreadsheet(Spreadsheet $ss, string $relativePath): void
    {
        $writer = new Xlsx($ss);
        $tmp = tempnam(sys_get_temp_dir(), 'estimation_');
        $writer->save($tmp);

        $dir = dirname($relativePath);
        if (! Storage::disk('local')->exists($dir)) {
            Storage::disk('local')->makeDirectory($dir);
        }
        Storage::disk('local')->put($relativePath, file_get_contents($tmp));

        @unlink($tmp);
    }

    /**
     * Expand a column range like A..AF into the full ordered list.
     */
    private function columnRange(string $from, string $to): array
    {
        $start = Coordinate::columnIndexFromString($from);
        $end = Coordinate::columnIndexFromString($to);
        $cols = [];
        for ($i = $start; $i <= $end; $i++) {
            $cols[] = Coordinate::stringFromColumnIndex($i);
        }

        return $cols;
    }
}
