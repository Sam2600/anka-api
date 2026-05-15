<?php

namespace Database\Seeders;

use App\Models\Deal;
use App\Models\Employee;
use App\Models\EstimationResource;
use App\Models\EstimationVersion;
use App\Models\Role;
use App\Services\EstimationXlsxService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Seeds three progressively richer estimation versions on a single deal so
 * the UI has interesting data to exercise without making real AI calls:
 *
 *   v1 — bare-bones features + role, no employee assignments
 *   v2 — same shape with employee_id picked per row (the new column)
 *   v3 — AI-style: _sheet1_summary + _sheet5_team_stack sentinels +
 *        per-row role / employee_id so the XLSX writer's Sheet 5 path runs
 *
 * Run standalone:
 *     php artisan db:seed --class=EstimationDemoSeeder
 *
 * Idempotent: wipes only this seeder's prior output (versions 1-3 plus their
 * estimation_resources rows on the target deal) before re-creating.
 */
class EstimationDemoSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguarded(function () {
            $deal = $this->pickTargetDeal();
            if (! $deal) {
                $this->command?->warn('EstimationDemoSeeder: no deal with workload_description found — run DatabaseSeeder first.');

                return;
            }

            // Tenant scope is enforced via BelongsToTenant; the bind here lets
            // Eloquent writes succeed without re-pulling from a request.
            app()->instance('tenant_id', $deal->tenant_id);

            $this->command?->info("Seeding estimation versions on deal: {$deal->name} ({$deal->id})");

            $rolesByTitle = Role::where('tenant_id', $deal->tenant_id)->get()->keyBy('title');
            $employees = Employee::where('tenant_id', $deal->tenant_id)
                ->where('status', 'Active')
                ->get();
            $empByRoleTitle = $this->groupEmployeesByRoleTitle($employees, $rolesByTitle);

            $this->wipePriorDemo($deal);

            $features = $this->demoFeatures();
            $admin = DB::table('users')->where('tenant_id', $deal->tenant_id)->first();

            $this->createVersion($deal, $admin, 1, $this->buildBaseline($features, $rolesByTitle), [], 'Baseline estimate — roles only, no staffing decision.');
            $this->createVersion($deal, $admin, 2, $this->buildWithEmployees($features, $rolesByTitle, $empByRoleTitle), [], 'Refined estimate — employees picked per row.');
            $this->createVersion($deal, $admin, 3, $this->buildAiStyle($features, $rolesByTitle, $empByRoleTitle), $this->aiSentinels($empByRoleTitle), 'AI-generated draft (demo) — includes Sheet 1 summary + Sheet 5 allocation sentinels.');

            $this->regenerateXlsx($deal);

            $this->command?->info('  ✓ 3 versions seeded with XLSX exports.');
        });
    }

    private function pickTargetDeal(): ?Deal
    {
        // Prefer a deal that's already past the win line so the post-win
        // filename convention kicks in. Falls back to any deal with a
        // workload description so the seeder still has something to seed
        // on a fresh dev DB.
        return Deal::query()
            ->withoutGlobalScopes()
            ->whereNotNull('workload_description')
            ->orderByRaw("CASE WHEN status = 'won' THEN 0 ELSE 1 END")
            ->orderBy('updated_at', 'desc')
            ->first();
    }

    /**
     * Group active employees by their job role's title. Used to pick the
     * "demo" employee per role deterministically — first one alphabetically.
     */
    private function groupEmployeesByRoleTitle($employees, $rolesByTitle): array
    {
        $roleIdToTitle = $rolesByTitle->mapWithKeys(fn ($r, $title) => [$r->id => $title])->all();
        $byTitle = [];
        foreach ($employees as $emp) {
            $title = $roleIdToTitle[$emp->job_role_id] ?? null;
            if (! $title) {
                continue;
            }
            $byTitle[$title][] = $emp;
        }
        foreach ($byTitle as $title => $list) {
            usort($byTitle[$title], fn ($a, $b) => strcmp($a->name, $b->name));
        }

        return $byTitle;
    }

    private function wipePriorDemo(Deal $deal): void
    {
        $versionIds = EstimationVersion::where('deal_id', $deal->id)
            ->whereIn('version_number', [1, 2, 3])
            ->pluck('id');

        if ($versionIds->isNotEmpty()) {
            EstimationVersion::whereIn('id', $versionIds)->delete();
        }

        EstimationResource::where('deal_id', $deal->id)->delete();
    }

    /**
     * 12 features spanning typical product surface area so role mix is
     * interesting. Hours are realistic for a small/medium agency build.
     */
    private function demoFeatures(): array
    {
        return [
            ['function_id' => 'F001', 'feature' => 'User Authentication & Role-Based Access Control', 'role' => 'Backend Engineer',    'hours' => 72,  'category' => 'Web'],
            ['function_id' => 'F002', 'feature' => 'Merchant Onboarding & Profile Management',         'role' => 'Backend Engineer',    'hours' => 64,  'category' => 'Web'],
            ['function_id' => 'F003', 'feature' => 'Wallet Dashboard Overview',                        'role' => 'Frontend Engineer',   'hours' => 56,  'category' => 'Web'],
            ['function_id' => 'F004', 'feature' => 'Transaction Ledger & History',                     'role' => 'Frontend Engineer',   'hours' => 48,  'category' => 'Web'],
            ['function_id' => 'F005', 'feature' => 'Reconciliation Engine (Core Logic)',               'role' => 'Solution Architect',  'hours' => 120, 'category' => 'Integration'],
            ['function_id' => 'F006', 'feature' => 'Discrepancy Management & Resolution Workflow',     'role' => 'Backend Engineer',    'hours' => 80,  'category' => 'Web'],
            ['function_id' => 'F007', 'feature' => 'Settlement Processing & Approval',                 'role' => 'Backend Engineer',    'hours' => 88,  'category' => 'Web'],
            ['function_id' => 'F008', 'feature' => 'Payment Gateway Integration',                      'role' => 'Solution Architect',  'hours' => 96,  'category' => 'Integration'],
            ['function_id' => 'F009', 'feature' => 'Bank / Core Banking System Integration',          'role' => 'Solution Architect',  'hours' => 80,  'category' => 'Integration'],
            ['function_id' => 'F010', 'feature' => 'Reporting Dashboard',                              'role' => 'Frontend Engineer',   'hours' => 72,  'category' => 'Web'],
            ['function_id' => 'F011', 'feature' => 'Scheduled & Ad-Hoc Report Generation',             'role' => 'Backend Engineer',    'hours' => 56,  'category' => 'Web'],
            ['function_id' => 'F012', 'feature' => 'Compliance Audit Trail & Logs',                    'role' => 'QA Engineer',         'hours' => 40,  'category' => 'Web'],
        ];
    }

    /** v1 — feature + role, no employee. */
    private function buildBaseline(array $features, $rolesByTitle): array
    {
        $rows = [];
        foreach ($features as $f) {
            $rows[] = [
                'roleId' => $rolesByTitle->get($f['role'])?->id,
                'employeeId' => null,
                'featureName' => $f['feature'],
                'hours' => $f['hours'],
            ];
        }

        return $rows;
    }

    /** v2 — assign first employee in each role alphabetically. */
    private function buildWithEmployees(array $features, $rolesByTitle, array $empByRoleTitle): array
    {
        $rows = [];
        foreach ($features as $f) {
            $employee = $empByRoleTitle[$f['role']][0] ?? null;
            $rows[] = [
                'roleId' => $rolesByTitle->get($f['role'])?->id,
                'employeeId' => $employee?->id,
                'featureName' => $f['feature'],
                'hours' => $f['hours'],
            ];
        }

        return $rows;
    }

    /**
     * v3 — same as v2 but also carries `role` per row (matches the AI-draft
     * shape) so it round-trips through the same code paths an AI-generated
     * save would. The sentinel rows are produced separately by aiSentinels().
     */
    private function buildAiStyle(array $features, $rolesByTitle, array $empByRoleTitle): array
    {
        $rows = [];
        foreach ($features as $f) {
            // Alternate between first and second employee per role so the v3
            // staffing differs from v2 — easier to eyeball the diff between
            // versions in the compare view.
            $candidates = $empByRoleTitle[$f['role']] ?? [];
            $employee = $candidates[1] ?? $candidates[0] ?? null;
            $rows[] = [
                'roleId' => $rolesByTitle->get($f['role'])?->id,
                'employeeId' => $employee?->id,
                'featureName' => $f['feature'],
                'hours' => $f['hours'],
                // role title is purely informational here — the controller
                // reads roleId; this just mirrors what the AI pipeline emits.
                'role' => $f['role'],
            ];
        }

        return $rows;
    }

    /**
     * Build the sentinel rows the XLSX writer reads to populate Sheet 1
     * summary numbers and Sheet 5 monthly allocations. Numbers are picked
     * to match a 4-month delivery window with a five-person team.
     */
    private function aiSentinels(array $empByRoleTitle): array
    {
        return [
            [
                '_sheet1_summary' => [
                    'rough_estimate_hours' => 120,
                    'requirement_study_hours' => 200,
                    'web_development_hours' => 540,
                    'environment_setup_hours' => 60,
                    'total_hours_per_person' => 920,
                    'total_days_per_person' => 115,
                    'total_months_per_person' => 5.75,
                ],
            ],
            [
                '_sheet5_team_stack' => [
                    ['role' => 'Project Manager',    'count' => 1, 'monthly_allocation' => [40, 60, 80, 40]],
                    ['role' => 'Solution Architect', 'count' => 1, 'monthly_allocation' => [80, 120, 140, 60]],
                    ['role' => 'Backend Engineer',   'count' => 1, 'monthly_allocation' => [60, 140, 160, 100]],
                    ['role' => 'Frontend Engineer',  'count' => 1, 'monthly_allocation' => [40, 120, 140, 80]],
                    ['role' => 'QA Engineer',        'count' => 1, 'monthly_allocation' => [0, 40, 100, 80]],
                ],
            ],
        ];
    }

    private function createVersion(Deal $deal, $admin, int $versionNumber, array $resourceRows, array $sentinels, string $notes): EstimationVersion
    {
        $jsonResources = array_merge($sentinels, $resourceRows);

        $version = EstimationVersion::create([
            'tenant_id' => $deal->tenant_id,
            'deal_id' => $deal->id,
            'version_number' => $versionNumber,
            'resources' => $jsonResources,
            'overheads' => [
                ['name' => 'Cloud infrastructure (4 mo)', 'cost' => 240000],
                ['name' => 'Third-party API licences',    'cost' => 180000],
            ],
            'target_margin' => 35,
            'notes' => $notes,
            'created_by' => $admin?->id,
            'created_at' => now()->subDays(7 - $versionNumber),
        ]);

        // The latest version's resources also sync into the relational
        // estimation_resources table — mirrors what the controller does on a
        // real save, so the deal page shows current data immediately.
        if ($versionNumber === 3) {
            EstimationResource::where('deal_id', $deal->id)->delete();
            foreach ($resourceRows as $row) {
                EstimationResource::create([
                    'tenant_id' => $deal->tenant_id,
                    'deal_id' => $deal->id,
                    'job_role_id' => $row['roleId'] ?? null,
                    'role_id' => $row['roleId'] ?? null,
                    'employee_id' => $row['employeeId'] ?? null,
                    'feature_name' => $row['featureName'],
                    'hours' => $row['hours'],
                ]);
            }
        }

        return $version;
    }

    private function regenerateXlsx(Deal $deal): void
    {
        $versions = EstimationVersion::where('deal_id', $deal->id)
            ->whereIn('version_number', [1, 2, 3])
            ->get();
        $service = app(EstimationXlsxService::class);
        foreach ($versions as $v) {
            try {
                $service->generateAndStore($v);
            } catch (Throwable $e) {
                // Don't fail the seed on a single bad export — log and move on.
                Log::warning('EstimationDemoSeeder: XLSX generation failed', [
                    'version_id' => $v->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
