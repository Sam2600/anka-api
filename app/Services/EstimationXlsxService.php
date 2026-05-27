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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalDataBar;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalFormatValueObject;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class EstimationXlsxService
{
    private const PHASES = [
        ['E', 'コードレビュー(h)\nCode Review', 0.10],
        ['F', 'Prototype(h)\nRequirement', 0.15],
        ['G', 'Prototypeレビュー(h)\nRequirement Review', 0.03],
        ['H', '業務フロー(h)\nWorkflow', 0.05],
        ['I', '業務フローレビュー(h)\nWorkflow Review', 0.08],
        ['J', 'ER図(h)\nER Diagram', 0.05],
        ['K', 'ER図レビュー(h)\nER Review', 0.04],
        ['L', 'DFD(h)\nDFD', 0.05],
        ['M', 'DFDレビュー(h)\nDFD Review', 0.30],
        ['N', 'DB設計(h)\nDB Design', 0.05],
        ['O', 'DB設計レビュー(h)\nDB Review', 0.10],
        ['P', '基本設計書(h)\nBasic Document', 0.20],
        ['Q', '基本設計レビュー(h)\nBasic Review', 0],
        ['R', '詳細設計(h)\nDetail Design', 0],
        ['S', '詳細設計レビュー(h)\nDetail Review', 0.50],
        ['T', '単体テスト仕様書(h)\nUnit Test Spec', 0.03],
        ['U', '単体テスト実施(h)\nUnit Test', 0.50],
        ['V', '結合テスト仕様書(h)\nIntegration Test Spec', 0.10],
        ['W', '結合テストレビュー(h)\nIntegration Review', 0.03],
        ['X', '結合テスト実施(h)\nIntegration Test', 0.30],
        ['Y', '総合テスト(h)\nSystem Test', 0.15],
        ['Z', 'テストデータ(h)\nTest Data', 0.10],
        ['AA', 'マニュアル(h)\nManual', 0.08],
    ];

    private const PHASE_GROUPS = [
        ['E', 'G', '開発'],
        ['H', 'I', '業務フロー'],
        ['J', 'O', '設計'],
        ['P', 'Q', '基本設計'],
        ['R', 'S', '詳細設計'],
        ['T', 'U', '単体テスト'],
        ['V', 'X', '結合テスト'],
        ['Y', 'Y', '総合テスト'],
        ['Z', 'Z', 'テストデータ'],
        ['AA', 'AA', 'マニュアル'],
    ];

    private const MILESTONE_PHASES = [
        '要件定義/Prototype',
        '基本全体設計',
        '基本設計書（各画面）',
        '実装(Web)',
        'テストデータ作成',
        '単体テスト',
        '結合テスト',
        '総合テスト',
        'マニュアル作成',
    ];

    private const S2_GROUP_ROW = 1;

    private const S2_MULT_ROW = 2;

    private const S2_HEADER_ROW = 3;

    private const S2_FIRST_DATA = 4;

    // Dev-parallel columns split across developers (not leader-owned)
    private const DEV_PARALLEL_COLS = ['D', 'S', 'U', 'X', 'Y'];

    public function generateAndStore(EstimationVersion $version): string
    {
        @ini_set('memory_limit', '512M');

        $deal = Deal::with(['estimation_resources', 'hard_assignments'])->findOrFail($version->deal_id);
        $project = Project::where('tenant_id', $deal->tenant_id)
            ->whereHas('contract', fn ($q) => $q->where('deal_id', $deal->id))
            ->first();

        $spreadsheet = $this->buildSpreadsheet($deal, $version);

        $path = $this->storagePathFor($deal, $project, $version);
        $this->writeSpreadsheet($spreadsheet, $path);

        DB::table('estimation_versions')->where('id', $version->id)->update(['xlsx_path' => $path]);
        $version->xlsx_path = $path;

        return $path;
    }

    public function migrateToProject(Deal $deal, Project $project): void
    {
        $versions = EstimationVersion::where('deal_id', $deal->id)->get();

        foreach ($versions as $version) {
            try {
                $newPath = $this->projectScopedPath($project, $version);
                $oldPath = $version->xlsx_path;

                if ($oldPath === $newPath && Storage::disk('local')->exists($newPath)) {
                    continue;
                }

                if ($oldPath && Storage::disk('local')->exists($oldPath)) {
                    $destDir = dirname($newPath);
                    if (! Storage::disk('local')->exists($destDir)) {
                        Storage::disk('local')->makeDirectory($destDir);
                    }
                    Storage::disk('local')->move($oldPath, $newPath);
                } else {
                    $this->generateAndStore($version);
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

    private function buildSpreadsheet(Deal $deal, EstimationVersion $version): Spreadsheet
    {
        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);

        $features = $this->extractFeatures($version);
        $members = $this->extractTeamMembers($deal, $version);

        $this->buildFunctionListSheet($ss, $features);
        $this->buildManhourDetailSheet($ss, $features);
        $this->buildMilestoneSheet($ss, $deal);
        $this->buildTeamStructureSheet($ss, $deal, $members);

        $ss->setActiveSheetIndex(0);

        return $ss;
    }

    // =========================================================================
    // Sheet 1: Web_Function List
    // =========================================================================

    private function buildFunctionListSheet(Spreadsheet $ss, array $features): void
    {
        $ws = new Worksheet($ss, 'Web_Function List');
        $ss->addSheet($ws);

        $headers = ['B' => 'No', 'C' => 'Function ID', 'D' => 'Function Name', 'E' => 'Explanation', 'F' => 'Module/Category'];
        foreach ($headers as $col => $label) {
            $ws->setCellValue($col.'1', $label);
        }
        $this->applyHeaderStyle($ws, 'B1:F1');

        foreach ($features as $i => $f) {
            $row = $i + 2;
            $ws->setCellValue('B'.$row, $i + 1);
            $ws->setCellValue('C'.$row, $f['function_id']);
            $ws->setCellValue('D'.$row, $f['name']);
            $ws->setCellValue('E'.$row, $f['explanation']);
            $ws->setCellValue('F'.$row, $f['category']);
        }

        $ws->getColumnDimension('A')->setWidth(3);
        $ws->getColumnDimension('B')->setWidth(8);
        $ws->getColumnDimension('C')->setWidth(16);
        $ws->getColumnDimension('D')->setWidth(37);
        $ws->getColumnDimension('E')->setWidth(50);
        $ws->getColumnDimension('F')->setWidth(22);
        $ws->freezePane('B2');

        $lastRow = count($features) + 1;
        $this->applyThinBorders($ws, "B1:F{$lastRow}");

        // Merge category cells for grouping
        $this->mergeCategoryCells($ws, $features);
    }

    private function mergeCategoryCells(Worksheet $ws, array $features): void
    {
        if (empty($features)) {
            return;
        }

        $startRow = 2;
        $currentCategory = $features[0]['category'] ?? '';

        for ($i = 1; $i < count($features); $i++) {
            $cat = $features[$i]['category'] ?? '';
            if ($cat !== $currentCategory) {
                if ($i > 1) {
                    $endRow = $i + 1;
                    if ($endRow - $startRow > 0) {
                        $ws->mergeCells("F{$startRow}:F".($endRow - 1));
                    }
                }
                $startRow = $i + 2;
                $currentCategory = $cat;
            }
        }

        $lastRow = count($features) + 1;
        if ($lastRow - $startRow > 0) {
            $ws->mergeCells("F{$startRow}:F{$lastRow}");
        }
    }

    // =========================================================================
    // Sheet 2: Web_Manhour_Detail
    // =========================================================================

    private function buildManhourDetailSheet(Spreadsheet $ss, array $features): void
    {
        $ws = new Worksheet($ss, 'Web_Manhour_Detail');
        $ss->addSheet($ws);

        $featureCount = count($features);
        $lastDataRow = self::S2_FIRST_DATA + $featureCount - 1;
        if ($featureCount === 0) {
            $lastDataRow = self::S2_FIRST_DATA;
        }

        $this->writeManhourGroupLabels($ws);
        $this->writeManhourMultipliers($ws);
        $this->writeManhourHeaders($ws);
        $this->writeManhourDataRows($ws, $features, $lastDataRow);
        $this->writeManhourSummary($ws, $featureCount, $lastDataRow);
        $this->applyManhourFormatting($ws, $featureCount, $lastDataRow);
    }

    private function writeManhourGroupLabels(Worksheet $ws): void
    {
        foreach (self::PHASE_GROUPS as [$startCol, $endCol, $label]) {
            $ws->setCellValue($startCol.self::S2_GROUP_ROW, $label);
            if ($startCol !== $endCol) {
                $ws->mergeCells($startCol.self::S2_GROUP_ROW.':'.$endCol.self::S2_GROUP_ROW);
            }
        }
        $ws->getStyle('E'.self::S2_GROUP_ROW.':AA'.self::S2_GROUP_ROW)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('E'.self::S2_GROUP_ROW.':AA'.self::S2_GROUP_ROW)->getFont()->setBold(true);
    }

    private function writeManhourMultipliers(Worksheet $ws): void
    {
        $ws->setCellValue('D'.self::S2_MULT_ROW, 'Base Dev(h)');
        $blueFont = ['font' => ['color' => ['rgb' => '0000FF']]];

        foreach (self::PHASES as [$col, $label, $mult]) {
            $ws->setCellValue($col.self::S2_MULT_ROW, $mult);
            $ws->getStyle($col.self::S2_MULT_ROW)->applyFromArray($blueFont);
        }
        $ws->getStyle('D'.self::S2_MULT_ROW)->applyFromArray($blueFont);
    }

    private function writeManhourHeaders(Worksheet $ws): void
    {
        $row = self::S2_HEADER_ROW;
        $headers = [
            'A' => '機能ID',
            'B' => '機能名称',
            'C' => 'Status',
            'D' => "開発工数(h)\nDevelop",
        ];
        foreach ($headers as $col => $label) {
            $ws->setCellValue($col.$row, $label);
        }

        foreach (self::PHASES as [$col, $label]) {
            $ws->setCellValue($col.$row, $label);
        }

        $ws->setCellValue('AB'.$row, "リスク(h)\nRisk");
        $ws->setCellValue('AC'.$row, "管理工数(h)\nManagement");
        $ws->setCellValue('AD'.$row, 'Total(h)');
        $ws->setCellValue('AE'.$row, '完了(h)');
        $ws->setCellValue('AF'.$row, '進捗%');
        $ws->setCellValue('AG'.$row, 'Progress Bar');

        // Header styling: orange background for A-C, yellow for D onwards
        $orangeFill = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FBE5D6'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'B0B0B0'],
                ],
            ],
        ];
        $yellowFill = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFE699'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'B0B0B0'],
                ],
            ],
        ];

        $ws->getStyle('A'.$row.':C'.$row)->applyFromArray($orangeFill);
        $ws->getStyle('D'.$row.':AG'.$row)->applyFromArray($yellowFill);
        $ws->getStyle('A'.$row.':AG'.$row)->getAlignment()->setWrapText(true);
    }

    private function writeManhourDataRows(Worksheet $ws, array $features, int $lastDataRow): void
    {
        $greenFont = ['font' => ['color' => ['rgb' => '00B050']]];

        foreach ($features as $i => $f) {
            $row = self::S2_FIRST_DATA + $i;
            $flRow = $i + 2; // Function List data starts at row 2

            // Cross-sheet links (green text) - updated for new column layout
            $ws->setCellValue("A{$row}", $f['function_id']);
            $ws->setCellValue("B{$row}", "='Web_Function List'!D{$flRow}");
            $ws->getStyle("B{$row}")->applyFromArray($greenFont);
            // Status derived from difficulty
            $ws->setCellValue("C{$row}", $f['difficulty']);

            // Dev hours from difficulty
            $ws->setCellValue("D{$row}", '=IF(C'.$row.'="難しい",32,IF(C'.$row.'="普通",8,IF(C'.$row.'="簡単",4,0)))');

            // Phase formulas
            foreach (self::PHASES as [$col]) {
                $ws->setCellValue("{$col}{$row}", "=D{$row}*\${$col}\$".self::S2_MULT_ROW);
            }

            // Risk and management
            $ws->setCellValue("AB{$row}", "=SUM(E{$row}:AA{$row})*0.03");
            $ws->setCellValue("AC{$row}", "=SUM(E{$row}:AA{$row})*0.10");

            // Total
            $ws->setCellValue("AD{$row}", "=SUM(D{$row}:AC{$row})");

            // Completed hours (AE) — left blank for manual entry

            // Progress %
            $ws->setCellValue("AF{$row}", "=IFERROR(AE{$row}/AD{$row},0)");

            // Progress bar
            $ws->setCellValue("AG{$row}", '=REPT("█",ROUND(AF'.$row.'*20,0))');
        }
    }

    private function writeManhourSummary(Worksheet $ws, int $featureCount, int $lastDataRow): void
    {
        if ($featureCount === 0) {
            return;
        }

        $first = self::S2_FIRST_DATA;
        $cyanFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'CAF8FA']]];
        $boldFont = ['font' => ['bold' => true]];

        // 1-person total row (2 rows below last data)
        $totalRow = $lastDataRow + 2;
        $ws->setCellValue("A{$totalRow}", '1人(Hr)');
        $ws->getStyle("A{$totalRow}")->applyFromArray($boldFont);

        $sumCols = array_merge(
            ['D'],
            array_map(fn ($p) => $p[0], self::PHASES),
            ['AB', 'AC', 'AD', 'AE']
        );
        foreach ($sumCols as $col) {
            $ws->setCellValue("{$col}{$totalRow}", "=SUM({$col}{$first}:{$col}{$lastDataRow})");
        }
        $ws->setCellValue("AF{$totalRow}", "=IFERROR(AE{$totalRow}/AD{$totalRow},0)");
        $ws->getStyle("A{$totalRow}:AG{$totalRow}")->applyFromArray($cyanFill);
        $ws->getStyle("A{$totalRow}:AG{$totalRow}")->applyFromArray($boldFont);

        // 1-person days
        $daysRow = $totalRow + 1;
        $ws->setCellValue("A{$daysRow}", '1人(Days)');
        foreach ($sumCols as $col) {
            if ($col === 'AE') {
                continue;
            }
            $ws->setCellValue("{$col}{$daysRow}", "={$col}{$totalRow}/8");
        }
        $ws->getStyle("A{$daysRow}:AG{$daysRow}")->applyFromArray($cyanFill);

        // 1-person months
        $monthsRow = $daysRow + 1;
        $ws->setCellValue("A{$monthsRow}", '1人(Months)');
        foreach ($sumCols as $col) {
            if ($col === 'AE') {
                continue;
            }
            $ws->setCellValue("{$col}{$monthsRow}", "={$col}{$daysRow}/20");
        }
        $ws->getStyle("A{$monthsRow}:AG{$monthsRow}")->applyFromArray($cyanFill);

        // Leader(1) + Developer(3) split - Hr
        $splitRow = $monthsRow + 2;
        $ws->setCellValue("A{$splitRow}", 'Leader  1人+ Developer 3人  (Hr)');
        $ws->getStyle("A{$splitRow}")->applyFromArray($boldFont);

        // Role count helper cells (matching template B85/B86 pattern)
        $leaderCountRow = $splitRow + 7;
        $devCountRow = $splitRow + 8;
        $ws->setCellValue("A{$leaderCountRow}", 'Leader');
        $ws->setCellValue("B{$leaderCountRow}", 1);
        $ws->setCellValue("A{$devCountRow}", 'Developer');
        $ws->setCellValue("B{$devCountRow}", 3);

        // Dev-parallel columns divided by Developer count
        foreach (self::DEV_PARALLEL_COLS as $col) {
            $ws->setCellValue("{$col}{$splitRow}", "={$col}{$totalRow}/B{$devCountRow}");
        }

        // Leader-owned columns = divided by Leader count
        $allPhaseCols = array_map(fn ($p) => $p[0], self::PHASES);
        $leaderCols = array_diff(
            array_merge($allPhaseCols, ['AB', 'AC']),
            self::DEV_PARALLEL_COLS
        );
        foreach ($leaderCols as $col) {
            $ws->setCellValue("{$col}{$splitRow}", "={$col}{$totalRow}/B{$leaderCountRow}");
        }

        // Risk and management use leader count
        $ws->setCellValue("AB{$splitRow}", "=AB{$totalRow}/B{$leaderCountRow}");
        $ws->setCellValue("AC{$splitRow}", "=AC{$totalRow}/B{$leaderCountRow}");

        // Total column
        $ws->setCellValue("AD{$splitRow}", "=AD{$totalRow}/B{$leaderCountRow}");
        $ws->setCellValue("AE{$splitRow}", "=SUM(D{$splitRow}:AD{$splitRow})");
        $ws->getStyle("A{$splitRow}:AG{$splitRow}")->applyFromArray($cyanFill);

        // Leader+Developer split - Days
        $splitDaysRow = $splitRow + 1;
        $ws->setCellValue("A{$splitDaysRow}", 'Leader  1人+ Developer 3人  (Days)');
        foreach ($sumCols as $col) {
            if ($col === 'AE') {
                continue;
            }
            $ws->setCellValue("{$col}{$splitDaysRow}", "={$col}{$splitRow}/8");
        }
        $ws->getStyle("A{$splitDaysRow}:AG{$splitDaysRow}")->applyFromArray($cyanFill);

        // Leader+Developer split - Months
        $splitMonthsRow = $splitRow + 2;
        $ws->setCellValue("A{$splitMonthsRow}", 'Leader  1人+ Developer 3人 (Months)');
        foreach ($sumCols as $col) {
            if ($col === 'AE') {
                continue;
            }
            $ws->setCellValue("{$col}{$splitMonthsRow}", "={$col}{$splitDaysRow}/20");
        }
        $ws->getStyle("A{$splitMonthsRow}:AG{$splitMonthsRow}")->applyFromArray($cyanFill);

        // Role totals section
        $roleHeaderRow = $splitMonthsRow + 2;
        $ws->setCellValue("D{$roleHeaderRow}", 'Total Hr');
        $ws->setCellValue("E{$roleHeaderRow}", 'Total Days');
        $ws->setCellValue("F{$roleHeaderRow}", 'Total Months');
        $ws->getStyle("D{$roleHeaderRow}:F{$roleHeaderRow}")->applyFromArray($boldFont);

        $leaderTotalRow = $roleHeaderRow + 1;
        $devTotalRow = $leaderTotalRow + 1;
        $combinedRow = $devTotalRow + 1;

        // Leader total (leader-owned columns from split row)
        $ws->setCellValue("A{$leaderTotalRow}", 'Leader');
        $ws->setCellValue("B{$leaderTotalRow}", 1);
        $leaderColRefs = implode('+', array_map(fn ($c) => "{$c}{$splitRow}", array_values($leaderCols)));
        $ws->setCellValue("D{$leaderTotalRow}", "={$leaderColRefs}");
        $ws->setCellValue("E{$leaderTotalRow}", "=D{$leaderTotalRow}/8");
        $ws->setCellValue("F{$leaderTotalRow}", "=E{$leaderTotalRow}/20");
        $ws->getStyle("A{$leaderTotalRow}:F{$leaderTotalRow}")->applyFromArray($boldFont);

        // Developer total (dev-parallel columns from split row)
        $ws->setCellValue("A{$devTotalRow}", 'Developer');
        $ws->setCellValue("B{$devTotalRow}", 3);
        $devColRefs = implode('+', array_map(fn ($c) => "{$c}{$splitRow}", self::DEV_PARALLEL_COLS));
        $ws->setCellValue("D{$devTotalRow}", "={$devColRefs}");
        $ws->setCellValue("E{$devTotalRow}", "=D{$devTotalRow}/8");
        $ws->setCellValue("F{$devTotalRow}", "=E{$devTotalRow}/20");
        $ws->getStyle("A{$devTotalRow}:F{$devTotalRow}")->applyFromArray($boldFont);

        // Combined project total
        $ws->setCellValue("A{$combinedRow}", 'Project Total');
        $ws->setCellValue("D{$combinedRow}", "=D{$leaderTotalRow}+(D{$devTotalRow}*B{$devCountRow})");
        $ws->setCellValue("E{$combinedRow}", "=D{$combinedRow}/8");
        $ws->setCellValue("F{$combinedRow}", "=E{$combinedRow}/20");
        $ws->getStyle("A{$combinedRow}:F{$combinedRow}")->applyFromArray($boldFont);
    }

    private function applyManhourFormatting(Worksheet $ws, int $featureCount, int $lastDataRow): void
    {
        // Column widths (matching template)
        $ws->getColumnDimension('A')->setWidth(13);
        $ws->getColumnDimension('B')->setWidth(33);
        $ws->getColumnDimension('C')->setWidth(8);
        $ws->getColumnDimension('D')->setWidth(12);

        foreach (self::PHASES as [$col]) {
            $ws->getColumnDimension($col)->setWidth(12);
        }
        $ws->getColumnDimension('AB')->setWidth(12);
        $ws->getColumnDimension('AC')->setWidth(14);
        $ws->getColumnDimension('AD')->setWidth(12);
        $ws->getColumnDimension('AE')->setWidth(10);
        $ws->getColumnDimension('AF')->setWidth(8);
        $ws->getColumnDimension('AG')->setWidth(22);

        // Freeze panes: rows 1-3 and column A
        $ws->freezePane('B'.self::S2_FIRST_DATA);

        if ($featureCount === 0) {
            return;
        }

        // Number format for hour columns
        $hourRange = 'D'.self::S2_FIRST_DATA.":AE{$lastDataRow}";
        $ws->getStyle($hourRange)->getNumberFormat()->setFormatCode('0.00');

        // Progress % format
        $ws->getStyle('AF'.self::S2_FIRST_DATA.":AF{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('0%');

        // Progress bar column: blue font
        $ws->getStyle('AG'.self::S2_FIRST_DATA.":AG{$lastDataRow}")
            ->getFont()->getColor()->setRGB('2196F3');

        // Thin borders on data area
        $this->applyThinBorders($ws, 'A'.self::S2_HEADER_ROW.":AG{$lastDataRow}");

        // DataBar conditional formatting on AF column
        try {
            $cfRange = 'AF'.self::S2_FIRST_DATA.":AF{$lastDataRow}";
            $minCfvo = new ConditionalFormatValueObject('num', '0');
            $maxCfvo = new ConditionalFormatValueObject('num', '1');
            $cf = new ConditionalDataBar;
            $cf->setMinimumConditionalFormatValueObject($minCfvo);
            $cf->setMaximumConditionalFormatValueObject($maxCfvo);
            $cf->setColor('2196F3');
            $conditional = new Conditional;
            $conditional->setConditionType(Conditional::CONDITION_DATABAR);
            $conditional->setDataBar($cf);
            $ws->setConditionalStyles($cfRange, [$conditional]);
        } catch (Throwable) {
            // DataBar may not be supported in all PhpSpreadsheet versions
        }
    }

    // =========================================================================
    // Sheet 3: Milestone
    // =========================================================================

    private function buildMilestoneSheet(Spreadsheet $ss, Deal $deal): void
    {
        $ws = new Worksheet($ss, 'Milestone');
        $ss->addSheet($ws);

        $start = $deal->expected_close_date
            ? Carbon::parse($deal->expected_close_date)->startOfMonth()
            : now()->startOfMonth();

        $timeline = max(4, (int) ($deal->timeline_months ?: 5));

        // Row 2-3: Phase label merged cell
        $ws->mergeCells('A2:A3');
        $ws->setCellValue('A2', 'PHASE I');
        $ws->getStyle('A2')->getFont()->setBold(true);
        $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Row 2: Month headers
        $weekCol = 'B';
        $monthStartCols = [];

        for ($m = 0; $m < $timeline; $m++) {
            $monthLabel = $start->copy()->addMonths($m)->format('Y/m (M)');
            $startCol = $weekCol;
            $monthStartCols[] = $startCol;

            $weeksInMonth = ($m === 1 && $timeline >= 5) ? 5 : 4;

            $ws->setCellValue($weekCol.'2', $monthLabel);

            // Row 3: Week sub-headers
            for ($w = 1; $w <= $weeksInMonth; $w++) {
                $ws->setCellValue($weekCol.'3', $w.'W');
                $ws->getColumnDimension($weekCol)->setWidth(4);
                $weekCol = $this->nextCol($weekCol);
            }

            $endCol = $this->prevCol($weekCol);
            if ($startCol !== $endCol) {
                $ws->mergeCells("{$startCol}2:{$endCol}2");
            }
        }

        $lastWeekCol = $this->prevCol($weekCol);
        $ws->getStyle('B2:'.$lastWeekCol.'2')->getFont()->setBold(true);
        $ws->getStyle('B2:'.$lastWeekCol.'2')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle('B3:'.$lastWeekCol.'3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Phase rows
        $scheduleFill = [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2196F3']],
            'font' => ['color' => ['rgb' => 'FFFFFF']],
        ];

        // Default schedule distribution (approximate)
        $phaseSchedule = $this->distributePhaseSchedule(count(self::MILESTONE_PHASES), $timeline);

        foreach (self::MILESTONE_PHASES as $pi => $phase) {
            $row = $pi + 4;
            $ws->setCellValue("A{$row}", $phase);

            if (isset($phaseSchedule[$pi])) {
                [$startWeek, $endWeek] = $phaseSchedule[$pi];
                $col = 'B';
                for ($w = 0; $w < $startWeek; $w++) {
                    $col = $this->nextCol($col);
                }
                for ($w = $startWeek; $w <= $endWeek && $w < ($timeline * 4); $w++) {
                    $ws->getStyle($col.$row)->applyFromArray($scheduleFill);
                    $col = $this->nextCol($col);
                }
            }
        }

        $ws->getColumnDimension('A')->setWidth(25);
        $ws->freezePane('B4');

        // Alternate row shading
        foreach (self::MILESTONE_PHASES as $pi => $phase) {
            $row = $pi + 4;
            if ($pi % 2 === 0) {
                $ws->getStyle("A{$row}:{$lastWeekCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F2F2');
            }
        }
    }

    private function distributePhaseSchedule(int $phaseCount, int $months): array
    {
        $totalWeeks = $months * 4;
        $schedule = [];
        $current = 0;

        // Rough allocation: requirements=2w, design=3w each, dev=40%, testing=30%, manual=1w
        $allocations = [
            [0.08, 0.08],   // 要件定義/Prototype
            [0.06, 0.06],   // 基本全体設計
            [0.08, 0.08],   // 基本設計書
            [0.25, 0.25],   // 実装(Web)
            [0.04, 0.04],   // テストデータ作成
            [0.12, 0.12],   // 単体テスト
            [0.12, 0.12],   // 結合テスト
            [0.10, 0.10],   // 総合テスト
            [0.05, 0.05],   // マニュアル作成
        ];

        $weekOffset = 0;
        foreach ($allocations as $pi => [$startFrac, $durFrac]) {
            $startWeek = (int) round($weekOffset);
            $duration = max(1, (int) round($durFrac * $totalWeeks));
            $endWeek = min($totalWeeks - 1, $startWeek + $duration - 1);
            $schedule[$pi] = [$startWeek, $endWeek];
            $weekOffset = $endWeek + 1;
        }

        return $schedule;
    }

    // =========================================================================
    // Sheet 4: 人の山積 (Team Structure)
    // =========================================================================

    private function buildTeamStructureSheet(Spreadsheet $ss, Deal $deal, array $members): void
    {
        $ws = new Worksheet($ss, '人的山積(Team Structure)');
        $ss->addSheet($ws);

        $start = $deal->expected_close_date
            ? Carbon::parse($deal->expected_close_date)->startOfMonth()
            : now()->startOfMonth();

        $timeline = max(1, (int) ($deal->timeline_months ?: 4));
        $visibleMonths = min(12, $timeline);

        // Row 3-4: Headers (matching template layout)
        $headerFill = [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DFEBF7']],
        ];

        $ws->mergeCells('B3:B4');
        $ws->setCellValue('B3', 'Assignee');
        $ws->getStyle('B3:B4')->applyFromArray($headerFill);

        // Year header spanning month columns
        $yearLabel = $start->format('Y');
        $monthCols = [];
        $col = 'D';
        for ($m = 0; $m < $visibleMonths; $m++) {
            $monthCols[] = $col;
            $col = $this->nextCol($col);
        }
        $lastMonthCol = $monthCols[count($monthCols) - 1];
        if ($visibleMonths > 1) {
            $ws->mergeCells("D3:{$lastMonthCol}3");
        }
        $ws->setCellValue('D3', $yearLabel);
        $ws->getStyle("D3:{$lastMonthCol}3")->applyFromArray($headerFill);

        // Row 4: Month numbers under the year
        foreach ($monthCols as $mci => $mc) {
            $monthNum = (int) $start->copy()->addMonths($mci)->format('n');
            $ws->setCellValue("{$mc}4", $monthNum);
            $ws->getStyle("{$mc}4")->applyFromArray($headerFill);
        }

        // SubTotal column
        $subtotalCol = $this->nextCol($lastMonthCol);
        $ws->mergeCells("{$subtotalCol}3:{$subtotalCol}4");
        $ws->setCellValue("{$subtotalCol}3", 'SubTotal');
        $ws->getStyle("{$subtotalCol}3:{$subtotalCol}4")->applyFromArray($headerFill);

        // Monthly Salary column
        $salaryCol = $this->nextCol($subtotalCol);
        $ws->mergeCells("{$salaryCol}3:{$salaryCol}4");
        $ws->setCellValue("{$salaryCol}3", 'Monthly Salary');
        $ws->getStyle("{$salaryCol}3:{$salaryCol}4")->applyFromArray($headerFill);

        // Apply header style
        $this->applyHeaderStyle($ws, "B3:{$salaryCol}4");

        // Data rows
        $firstMemberRow = 5;
        $blueFont = ['font' => ['color' => ['rgb' => '0000FF']]];

        foreach ($members as $mi => $m) {
            $row = $firstMemberRow + $mi;
            $ws->setCellValue("B{$row}", $m['name']);

            $firstMonthCol = $monthCols[0];
            $lastMonthCol = $monthCols[count($monthCols) - 1];

            $alloc = $m['monthly_allocation'] ?? [];
            foreach ($monthCols as $mci => $mc) {
                $val = (float) ($alloc[$mci] ?? 0);
                if ($val > 0) {
                    $ws->setCellValue("{$mc}{$row}", round($val, 1));
                    $ws->getStyle("{$mc}{$row}")->applyFromArray($blueFont);
                }
            }

            // SubTotal
            $ws->setCellValue("{$subtotalCol}{$row}", "=SUM({$firstMonthCol}{$row}:{$lastMonthCol}{$row})");

            // Monthly Salary from system data
            $salary = (float) ($m['monthly_salary'] ?? 0);
            if ($salary > 0) {
                $ws->setCellValue("{$salaryCol}{$row}", $salary);
                $ws->getStyle("{$salaryCol}{$row}")->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        $lastMemberRow = $firstMemberRow + max(0, count($members) - 1);
        if (count($members) === 0) {
            $lastMemberRow = $firstMemberRow;
        }

        // Total (H) row - yellow background
        $totRow = $lastMemberRow + 1;
        $yellowFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']]];
        $ws->setCellValue("B{$totRow}", 'Total (H)');
        $ws->getStyle("B{$totRow}")->getFont()->setBold(true);

        foreach ($monthCols as $mc) {
            $ws->setCellValue("{$mc}{$totRow}", "=SUM({$mc}{$firstMemberRow}:{$mc}{$lastMemberRow})");
        }
        $ws->setCellValue(
            "{$subtotalCol}{$totRow}",
            "=SUM({$subtotalCol}{$firstMemberRow}:{$subtotalCol}{$lastMemberRow})"
        );
        $ws->getStyle("B{$totRow}:{$subtotalCol}{$totRow}")->applyFromArray($yellowFill);
        $ws->getStyle("B{$totRow}:{$subtotalCol}{$totRow}")->getFont()->setBold(true);

        // Total Price row - green background
        $priceRow = $totRow + 2;
        $greenFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'A9D18E']]];
        $ws->setCellValue("H{$priceRow}", 'Total Price');
        $ws->getStyle("H{$priceRow}")->applyFromArray($greenFill);
        $ws->getStyle("H{$priceRow}")->getFont()->setBold(true);

        // Price Per Month row - green background
        $pricePerMonthRow = $priceRow + 1;
        $ws->setCellValue("B{$pricePerMonthRow}", 'Price Per Month');
        $ws->getStyle("B{$pricePerMonthRow}")->applyFromArray($greenFill);

        // Build formula for each month: Σ(allocation × monthly_salary)
        foreach ($monthCols as $mc) {
            $terms = [];
            for ($r = $firstMemberRow; $r <= $lastMemberRow; $r++) {
                $terms[] = "{$mc}{$r}*{$salaryCol}{$r}";
            }
            $formula = '='.implode('+', $terms);
            $ws->setCellValue("{$mc}{$pricePerMonthRow}", $formula);
            $ws->getStyle("{$mc}{$pricePerMonthRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $ws->setCellValue(
            "{$subtotalCol}{$pricePerMonthRow}",
            "=SUM({$monthCols[0]}{$pricePerMonthRow}:{$lastMonthCol}{$pricePerMonthRow})"
        );
        $ws->getStyle("{$subtotalCol}{$pricePerMonthRow}")->getNumberFormat()->setFormatCode('#,##0');
        $ws->getStyle("H{$priceRow}:{$subtotalCol}{$pricePerMonthRow}")->applyFromArray($greenFill);

        // Column widths
        $ws->getColumnDimension('A')->setWidth(3);
        $ws->getColumnDimension('B')->setWidth(20);
        $ws->getColumnDimension('C')->setWidth(3);
        foreach ($monthCols as $mc) {
            $ws->getColumnDimension($mc)->setWidth(11);
        }
        $ws->getColumnDimension($subtotalCol)->setWidth(13);
        $ws->getColumnDimension($salaryCol)->setWidth(15);

        $ws->freezePane('D5');
    }

    // =========================================================================
    // Data extraction
    // =========================================================================

    private function extractFeatures(EstimationVersion $version): array
    {
        $out = [];
        foreach (($version->resources ?? []) as $i => $r) {
            if ($this->isSentinelRow($r)) {
                continue;
            }
            $name = $r['feature_name'] ?? $r['featureName'] ?? '';
            if ($name === '') {
                continue;
            }
            $hours = (float) ($r['hours'] ?? 0);
            $out[] = [
                'function_id' => $r['function_id'] ?? sprintf('F%03d', count($out) + 1),
                'name' => $name,
                'explanation' => $r['explanation'] ?? '',
                'category' => $r['category'] ?? 'Web',
                'status' => $r['status'] ?? '',
                'difficulty' => $r['difficulty'] ?? $this->deriveDifficulty($hours),
                'dev_hours' => $hours,
            ];
        }

        return $out;
    }

    private function deriveDifficulty(float $hours): string
    {
        if ($hours <= 6) {
            return '簡単';
        }
        if ($hours <= 20) {
            return '普通';
        }

        return '難しい';
    }

    private function extractTeamMembers(Deal $deal, EstimationVersion $version): array
    {
        // (1) AI-generated sentinel
        foreach (($version->resources ?? []) as $r) {
            if (isset($r['_sheet5_team_stack']) && is_array($r['_sheet5_team_stack'])) {
                $out = [];
                foreach ($r['_sheet5_team_stack'] as $entry) {
                    $alloc = $entry['monthly_allocation'] ?? [];
                    $roleName = (string) ($entry['role'] ?? 'Role');
                    $count = max(1, (int) ($entry['count'] ?? 1));
                    $monthlySalary = $this->resolveMonthlySalary($deal, $version, $roleName, $entry);

                    for ($m = 0; $m < $count; $m++) {
                        $suffix = $count > 1 ? ' '.($m + 1) : '';
                        $out[] = [
                            'name' => $roleName.$suffix,
                            'role_type' => $entry['role_type'] ?? $this->guessRoleType($roleName),
                            'monthly_allocation' => is_array($alloc) ? array_map('floatval', $alloc) : [],
                            'monthly_salary' => $monthlySalary,
                        ];
                    }
                }
                if (! empty($out)) {
                    return $out;
                }
            }
        }

        $timeline = max(1, (int) ($deal->timeline_months ?: 4));
        $visibleMonths = $timeline;

        // (2) Per-employee aggregate
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
                $emp = $employees[$empId] ?? null;
                $perMonth = $totalHours / $timeline;
                $alloc = array_fill(0, $visibleMonths, 0.0);
                for ($i = 0; $i < min($visibleMonths, $timeline); $i++) {
                    $alloc[$i] = round($perMonth, 1);
                }
                $out[] = [
                    'name' => $emp?->name ?? 'Employee',
                    'role_type' => $this->guessEmployeeRoleType($emp),
                    'monthly_allocation' => $alloc,
                    'monthly_salary' => $emp?->monthly_salary ?? 0,
                ];
            }

            return $out;
        }

        // (3) Hard-assignment fallback
        if ($deal->relationLoaded('hard_assignments') && $deal->hard_assignments->isNotEmpty()) {
            $out = [];
            foreach ($deal->hard_assignments as $ha) {
                $employee = Employee::find($ha->employee_id);
                $perMonth = ((float) $ha->allocated_hours) / $timeline;
                $alloc = array_fill(0, $visibleMonths, 0.0);
                for ($i = 0; $i < min($visibleMonths, $timeline); $i++) {
                    $alloc[$i] = round($perMonth, 1);
                }
                $out[] = [
                    'name' => $employee?->name ?? 'Employee',
                    'role_type' => $this->guessEmployeeRoleType($employee),
                    'monthly_allocation' => $alloc,
                    'monthly_salary' => $employee?->monthly_salary ?? 0,
                ];
            }

            return $out;
        }

        // (4) Role aggregate
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
            $role = $roles[$roleId] ?? null;
            $perMonth = $totalHours / $timeline;
            $alloc = array_fill(0, $visibleMonths, 0.0);
            for ($i = 0; $i < min($visibleMonths, $timeline); $i++) {
                $alloc[$i] = round($perMonth, 1);
            }
            $out[] = [
                'name' => $role?->title ?? 'Role',
                'role_type' => 'S Member',
                'monthly_allocation' => $alloc,
                'monthly_salary' => $role?->rate ?? 0,
            ];
        }

        return $out;
    }

    private function resolveMonthlySalary(Deal $deal, EstimationVersion $version, string $roleName, array $entry): float
    {
        $employees = Employee::where('tenant_id', $deal->tenant_id)
            ->where(function ($q) use ($roleName) {
                $lower = mb_strtolower($roleName);
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(role_name) LIKE ?', ["%{$lower}%"]);
            })
            ->get();

        if ($employees->isNotEmpty()) {
            return (float) $employees->first()->monthly_salary;
        }

        $tenantAvg = Employee::where('tenant_id', $deal->tenant_id)
            ->where('monthly_salary', '>', 0)
            ->avg('monthly_salary');

        return $tenantAvg ? (float) $tenantAvg : 0;
    }

    private function guessRoleType(string $roleName): string
    {
        $lower = mb_strtolower($roleName);
        if (str_contains($lower, 'leader') || str_contains($lower, 'manager') || str_contains($lower, 'リーダ')) {
            return 'Leader';
        }
        if (str_contains($lower, 'junior') || str_contains($lower, 'ジュニア') || str_contains($lower, 'j ')) {
            return 'J Member';
        }

        return 'S Member';
    }

    private function guessEmployeeRoleType(?Employee $employee): string
    {
        if (! $employee) {
            return 'S Member';
        }
        $role = mb_strtolower($employee->role_name ?? '');
        if (str_contains($role, 'leader') || str_contains($role, 'manager')) {
            return 'Leader';
        }
        if (str_contains($role, 'junior')) {
            return 'J Member';
        }

        return 'S Member';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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

    private function applyHeaderStyle(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'BDD7EE'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'B0B0B0'],
                ],
            ],
        ]);
    }

    private function applyThinBorders(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D0D0D0'],
                ],
            ],
        ]);
    }

    private function nextCol(string $col): string
    {
        $idx = Coordinate::columnIndexFromString($col);

        return Coordinate::stringFromColumnIndex($idx + 1);
    }

    private function prevCol(string $col): string
    {
        $idx = Coordinate::columnIndexFromString($col);

        return Coordinate::stringFromColumnIndex(max(1, $idx - 1));
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
}
