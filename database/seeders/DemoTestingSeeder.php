<?php

namespace Database\Seeders;

use App\Models\CapacityRole;
use App\Models\CompanySetting;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealGhostRole;
use App\Models\DealHardAssignment;
use App\Models\DealOverhead;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\EstimationResource;
use App\Models\EstimationVersion;
use App\Models\Holiday;
use App\Models\InitialBudget;
use App\Models\Invoice;
use App\Models\PhaseProgressLog;
use App\Models\ProjectTaskAssignment;
use App\Models\ProjectTaskPhaseAssignment;
use App\Models\Project;
use App\Models\ProjectTeamAssignment;
use App\Models\Rank;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo Testing Seeder — independent snapshot of the current demo tenant
 * data shape for QA / regression. Same business rules as the live demo,
 * different tenant + slug + email domain so it can coexist.
 *
 *   • 15 employees:
 *       - 3 IT Leaders  → basic 4,026,667 (cost/hr 25,166.67)
 *       - 9 IT Members  → basic 2,585,714 (cost/hr 16,160.71)
 *       - 1 Sales       → basic 1,000,000, workable_hours 160 (cost only,
 *                         Sell/Hr hidden by UI for non-IT)
 *       - 2 HR          → basic 1,000,000 + 700,000, same treatment
 *   • 4 projects matching the spec sheet within ≤ 5 MMK rounding:
 *       - S-Project1   Jan–Aug · 1L+3M · 216.8M / 108.4M / +108.4M
 *                      OT spread Feb 20h, Mar 60h, Apr 60h, May 60h = 200h
 *                      (~176h logged through May 18). Phase logs surface
 *                      `used > progress` so OT Impact lights up correctly.
 *       - S-Project2   Mar–Dec · 1L+2M · 211.6M / 105.8M / +105.8M
 *       - A-Project1   Jul–Oct · negotiation rank · full rollup
 *                      (no final_confirmed_at — Forecast keys off
 *                      expectedCloseDate + 1 day)
 *       - B-Project1   Oct–Dec · qualified rank · expected_close_date =
 *                      Sep 30 so Forecast plots from Oct
 *   • No deal_overheads (15% overhead is folded into the cost rate).
 *   • Invoices, time entries, ProjectTeamAssignments, schedule history
 *     (task / phase logs), Japanese holidays — all seeded consistently.
 *
 * Idempotent — wipes only its own slug.
 *
 *   php artisan db:seed --class=DemoTestingSeeder
 */
class DemoTestingSeeder extends Seeder
{
    private const SLUG = 'brycen-myanmar-testing';
    private const PASSWORD = 'Demo@1234';
    private const EMAIL_DOMAIN = 'testing.brycenmyanmar.com.mm';
    private const OVERHEAD_PCT = 15;
    private const SELL_MULTIPLIER = 2;

    public function run(): void
    {
        Model::unguarded(function () {
            $this->wipeExisting();

            $tenant = Tenant::create([
                'name' => 'Brycen Myanmar (Testing)',
                'slug' => self::SLUG,
                'plan' => 'pro',
                'currency' => 'MMK',
                'is_active' => true,
                'signatory_name' => 'Leader1',
                'signatory_title' => 'Managing Director',
            ]);
            app()->instance('tenant_id', $tenant->id);

            $capacityRoles = $this->createCapacityRoles($tenant);
            $ranks = $this->createRanks($tenant);
            $departments = $this->createDepartments($tenant);
            $roles = $this->createRoles($tenant, $departments);
            $skills = $this->createSkills($tenant);
            $employees = $this->createEmployees($tenant, $departments, $roles, $capacityRoles, $ranks);
            $this->attachEmployeeSkills($tenant, $employees, $skills);
            $users = $this->createUsers($tenant, $employees);
            $admin = $users['leader1'];
            $this->finishDepartments($departments, $employees);
            $this->createCompanySettings($tenant);
            $this->seedJapanHolidays($tenant);

            $yearStart = Carbon::create(2026, 1, 1);

            $this->createSProject1($tenant, $employees, $roles, $admin, $yearStart);
            $this->createSProject2($tenant, $employees, $roles, $admin, $yearStart);
            $this->createAProject1($tenant, $employees, $roles, $admin, $yearStart);
            $this->createBProject1($tenant, $employees, $roles, $admin, $yearStart);

            $this->command->info('Brycen Myanmar (Testing) tenant seeded.');
            $this->command->info('  Tenant ID: '.$tenant->id);
            $this->command->info('  Logins (password = '.self::PASSWORD.'):');
            foreach ($users as $u) {
                $this->command->info('    '.str_pad($u->app_role, 10).' '.$u->email);
            }
        });
    }

    private function wipeExisting(): void
    {
        $tenants = Tenant::where('slug', self::SLUG)->get();
        foreach ($tenants as $tenant) {
            $this->wipeTenant($tenant->id);
        }
    }

    private function wipeTenant(string $tenantId): void
    {
        foreach ([
            'ai_usage_logs',
            'audit_logs',
            'personal_access_tokens',
            'phase_progress_logs',
            'project_task_phase_assignments',
            'project_task_assignments',
            'time_entries',
            'project_team_assignments',
            'invoices',
            'milestones',
            'projects',
            'contracts',
            'estimation_versions',
            'deal_contract_drafts',
            'deal_contract_documents',
            'deal_overheads',
            'estimation_resources',
            'deal_hard_assignments',
            'deal_ghost_roles',
            'deals',
            'employee_skills',
            'employee_salary_history',
            'users',
            'employees',
            'skills',
            'ranks',
            'capacity_roles',
            'roles',
            'departments',
            'global_overheads',
            'initial_budgets',
            'company_settings',
            'holidays',
            'tenant_app_role_permissions',
            'tenant_app_roles',
        ] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        }
        DB::table('tenants')->where('id', $tenantId)->delete();
    }

    private function createCapacityRoles(Tenant $tenant): array
    {
        $roles = [];
        foreach ([
            'pm' => 'Project Manager',
            'backend' => 'Backend Engineer',
            'frontend' => 'Frontend Engineer',
            'design' => 'Product Designer',
            'qa' => 'QA Engineer',
        ] as $code => $name) {
            $roles[$code] = CapacityRole::create([
                'tenant_id' => $tenant->id,
                'code' => $code,
                'name' => $name,
            ]);
        }

        return $roles;
    }

    private function createRanks(Tenant $tenant): array
    {
        $ranks = [];
        foreach ([
            ['code' => 'Junior',    'name' => 'Junior',           'level' => 10],
            ['code' => 'Mid',       'name' => 'Mid-Level',        'level' => 20],
            ['code' => 'Senior',    'name' => 'Senior',           'level' => 30],
            ['code' => 'Lead',      'name' => 'Lead / Tech Lead', 'level' => 40],
            ['code' => 'Manager',   'name' => 'Manager',          'level' => 50],
            ['code' => 'Director',  'name' => 'Director',         'level' => 70],
            ['code' => 'Executive', 'name' => 'Executive',        'level' => 90],
        ] as $row) {
            $ranks[$row['code']] = Rank::create([
                'tenant_id' => $tenant->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'level' => $row['level'],
            ]);
        }

        return $ranks;
    }

    private function createDepartments(Tenant $tenant): array
    {
        $deps = [];
        foreach (['IT', 'Sales', 'HR'] as $name) {
            $deps[$name] = Department::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'headcount' => 0,
            ]);
        }

        return $deps;
    }

    private function createRoles(Tenant $tenant, array $departments): array
    {
        $rows = [
            ['title' => 'IT Leader',  'department' => 'IT',    'rate' => 58_000],
            ['title' => 'IT Member',  'department' => 'IT',    'rate' => 29_000],
            ['title' => 'Sales',      'department' => 'Sales', 'rate' => 7_000],
            ['title' => 'HR',         'department' => 'HR',    'rate' => 7_000],
        ];

        $out = [];
        foreach ($rows as $row) {
            $dept = $departments[$row['department']];
            $out[$row['title']] = Role::create([
                'tenant_id' => $tenant->id,
                'department_id' => $dept->id,
                'title' => $row['title'],
                'department' => $dept->name,
                'rate' => $row['rate'],
            ]);
        }

        return $out;
    }

    private function createEmployees(
        Tenant $tenant,
        array $departments,
        array $roles,
        array $capacityRoles,
        array $ranks,
    ): array {
        $rows = [
            // IT Leaders flattened to rank-avg (sheet's 4,650,667 costPrice ÷ 1.15 = 4,026,667 salary)
            ['key' => 'leader1', 'name' => 'Leader1', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',      'rank' => 'Lead',   'basic' => 4_026_667, 'allow' => 0],
            ['key' => 'leader2', 'name' => 'Leader2', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',      'rank' => 'Lead',   'basic' => 4_026_667, 'allow' => 0],
            ['key' => 'leader3', 'name' => 'Leader3', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',      'rank' => 'Lead',   'basic' => 4_026_667, 'allow' => 0],
            // IT Members flattened to rank-avg (sheet's 2,973,571 costPrice ÷ 1.15 = 2,585,714 salary)
            ['key' => 'member1', 'name' => 'Member1', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member2', 'name' => 'Member2', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member3', 'name' => 'Member3', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member4', 'name' => 'Member4', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member5', 'name' => 'Member5', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member6', 'name' => 'Member6', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member7', 'name' => 'Member7', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'qa',       'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member8', 'name' => 'Member8', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            ['key' => 'member9', 'name' => 'Member9', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_585_714, 'allow' => 0],
            // Sales / HR are non-billable departments — they have a cost price
            // (salary × 1.15, shown on /organization) but no sell price (per spec
            // sheet's greyed-out sellPrice column for these rows).
            ['key' => 'member10','name' => 'Member10','role' => 'Sales',     'dep' => 'Sales','cap' => 'pm',    'rank' => 'Mid',    'basic' => 1_000_000, 'allow' => 0],
            ['key' => 'member11','name' => 'Member11','role' => 'HR',        'dep' => 'HR',   'cap' => 'pm',    'rank' => 'Mid',    'basic' => 1_000_000, 'allow' => 0],
            ['key' => 'member12','name' => 'Member12','role' => 'HR',        'dep' => 'HR',   'cap' => 'pm',    'rank' => 'Junior', 'basic' => 700_000,   'allow' => 0],
        ];

        $employees = [];
        foreach ($rows as $row) {
            $role = $roles[$row['role']];
            $employees[$row['key']] = Employee::create([
                'tenant_id' => $tenant->id,
                'department_id' => $departments[$row['dep']]->id,
                'job_role_id' => $role->id,
                'name' => $row['name'],
                'role' => $row['role'],
                'role_name' => $row['role'],
                'capacity_role' => $row['cap'],
                'capacity_role_id' => $capacityRoles[$row['cap']]->id,
                'rank_id' => $ranks[$row['rank']]->id,
                'basic_salary' => $row['basic'],
                'allowance' => $row['allow'],
                'workable_hours' => $row['workable_hours'] ?? 160,
                'status' => 'Active',
            ])->fresh();
        }

        return $employees;
    }

    /**
     * @return array<string, User>
     */
    private function createUsers(Tenant $tenant, array $employees): array
    {
        $appRoleByDept = [
            'Sales' => 'Sales',
            'HR' => 'HR',
        ];

        $users = [];
        foreach ($employees as $key => $employee) {
            if ($key === 'leader1') {
                $appRole = 'Admin';
            } elseif (in_array($key, ['leader2', 'leader3'], true)) {
                $appRole = 'Executive';
            } else {
                $deptName = optional($employee->department)->name;
                $appRole = $appRoleByDept[$deptName] ?? 'Delivery';
            }

            $nameParts = explode(' ', trim($employee->name), 2);
            $first = $nameParts[0];
            $last = $nameParts[1] ?? '';

            $users[$key] = User::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($key).'@'.self::EMAIL_DOMAIN,
                'password' => Hash::make(self::PASSWORD),
                'system_role' => 'member',
                'app_role' => $appRole,
                'is_super_admin' => false,
            ]);
        }

        return $users;
    }

    private function finishDepartments(array $departments, array $employees): void
    {
        $managers = [
            'IT' => 'leader1',
            'Sales' => 'member10',
            'HR' => 'member11',
        ];

        foreach ($departments as $name => $department) {
            $headcount = collect($employees)
                ->filter(fn (Employee $e) => $e->department_id === $department->id)
                ->count();
            $manager = $employees[$managers[$name] ?? null] ?? null;
            $department->update([
                'manager_id' => $manager?->id,
                'manager' => $manager?->name,
                'headcount' => $headcount,
            ]);
        }
    }

    private function createCompanySettings(Tenant $tenant): void
    {
        CompanySetting::create([
            'id' => $tenant->id,
            'tenant_id' => $tenant->id,
            'overhead_percentage' => self::OVERHEAD_PCT,
            'buffer_percentage' => 0,
            'yearly_fixed_cost' => 0,
            'annual_initial_budget' => 120_000_000,
            'employer_tax_percentage' => 0,
            'benefits_percentage' => 0,
            'cost_to_bill_ratio' => 1 / self::SELL_MULTIPLIER,
            'default_monthly_capacity_hours' => 160,
            'fallback_hourly_cost' => 12_500,
        ]);

        InitialBudget::create([
            'tenant_id' => $tenant->id,
            'fiscal_year' => 2026,
            'amount' => 120_000_000,
        ]);
    }

    private function createSkills(Tenant $tenant): array
    {
        $rows = [
            'Laravel'      => 'Technical',
            'React'        => 'Technical',
            'PostgreSQL'   => 'Technical',
            'AWS'          => 'Technical',
            'Database'     => 'Technical',
            'TypeScript'   => 'Technical',
            'Docker'       => 'Technical',
            'QA Automation'=> 'Technical',
            'UI/UX Design' => 'Creative',
            'Project Management' => 'Management',
            'Client Relations'   => 'Management',
        ];
        $out = [];
        foreach ($rows as $name => $category) {
            $out[$name] = Skill::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'category' => $category,
            ]);
        }

        return $out;
    }

    private function attachEmployeeSkills(Tenant $tenant, array $employees, array $skills): void
    {
        $matrix = [
            'leader1'  => [['Project Management', 'expert'], ['Client Relations', 'expert'], ['AWS', 'intermediate']],
            'leader2'  => [['Project Management', 'expert'], ['Laravel', 'intermediate'], ['PostgreSQL', 'intermediate']],
            'leader3'  => [['Project Management', 'expert'], ['React', 'intermediate'], ['Docker', 'intermediate']],
            'member1'  => [['Laravel', 'expert'],  ['PostgreSQL', 'expert'], ['AWS', 'intermediate']],
            'member2'  => [['Laravel', 'expert'],  ['Database', 'expert'],   ['Docker', 'intermediate']],
            'member3'  => [['React', 'expert'],    ['TypeScript', 'expert']],
            'member4'  => [['Laravel', 'expert'],  ['PostgreSQL', 'intermediate']],
            'member5'  => [['React', 'expert'],    ['TypeScript', 'intermediate']],
            'member6'  => [['Laravel', 'expert'],  ['AWS', 'intermediate']],
            'member7'  => [['QA Automation', 'expert'], ['Database', 'intermediate']],
            'member8'  => [['Laravel', 'intermediate'], ['Database', 'intermediate']],
            'member9'  => [['React', 'expert'],    ['UI/UX Design', 'intermediate']],
            'member10' => [['Client Relations', 'expert']],
            'member11' => [['Client Relations', 'expert']],
            'member12' => [['Client Relations', 'intermediate']],
        ];

        foreach ($matrix as $empKey => $skillRows) {
            $employee = $employees[$empKey] ?? null;
            if (! $employee) {
                continue;
            }
            foreach ($skillRows as [$skillName, $proficiency]) {
                EmployeeSkill::create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'skill_id' => $skills[$skillName]->id,
                    'proficiency' => $proficiency,
                ]);
            }
        }
    }

    private function seedJapanHolidays(Tenant $tenant): void
    {
        $currentYear = (int) date('Y');
        $years = [$currentYear, $currentYear + 1, $currentYear + 2];
        $equinox = [
            2024 => ['03-20', '09-22'],
            2025 => ['03-20', '09-23'],
            2026 => ['03-20', '09-23'],
            2027 => ['03-21', '09-23'],
            2028 => ['03-20', '09-22'],
            2029 => ['03-20', '09-23'],
            2030 => ['03-20', '09-23'],
        ];

        foreach ($years as $y) {
            $fixed = [
                ['01-01', '元日'],
                ['02-11', '建国記念の日'],
                ['02-23', '天皇誕生日'],
                ['04-29', '昭和の日'],
                ['05-03', '憲法記念日'],
                ['05-04', 'みどりの日'],
                ['05-05', 'こどもの日'],
                ['08-11', '山の日'],
                ['11-03', '文化の日'],
                ['11-23', '勤労感謝の日'],
            ];
            foreach ($fixed as [$md, $name]) {
                Holiday::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'date' => $y.'-'.$md],
                    ['name' => $name, 'is_recurring' => true]
                );
            }

            $monday = fn (int $month, int $occurrence) => Carbon::create($y, $month, 1)
                ->modify(($occurrence).' monday')
                ->toDateString();
            foreach ([
                [1,  2, '成人の日'],
                [7,  3, '海の日'],
                [9,  3, '敬老の日'],
                [10, 2, 'スポーツの日'],
            ] as [$month, $occ, $name]) {
                Holiday::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'date' => $monday($month, $occ)],
                    ['name' => $name, 'is_recurring' => false]
                );
            }

            if (isset($equinox[$y])) {
                Holiday::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'date' => $y.'-'.$equinox[$y][0]],
                    ['name' => '春分の日', 'is_recurring' => false]
                );
                Holiday::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'date' => $y.'-'.$equinox[$y][1]],
                    ['name' => '秋分の日', 'is_recurring' => false]
                );
            }
        }
    }

    // ── Projects ─────────────────────────────────────────────────────

    /**
     * S-Project1 — Jan–Aug 2026, 1 Leader + 3 Members.
     * Sheet's OT pattern: Feb 20h, Mar 60h, Apr 60h, May 60h = 200h total.
     * Cost override: 108,411,048 total (sheet's 13,551,381 × 8 months).
     */
    private function createSProject1(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart): void
    {
        $team = [
            ['emp' => $employees['leader1'],  'role_code' => 'pm',      'feature' => 'Tech lead + delivery oversight', 'hours_per_month' => 160],
            ['emp' => $employees['member1'],  'role_code' => 'backend', 'feature' => 'Core service implementation',     'hours_per_month' => 160],
            ['emp' => $employees['member2'],  'role_code' => 'backend', 'feature' => 'Integration layer',               'hours_per_month' => 160],
            ['emp' => $employees['member3'],  'role_code' => 'backend', 'feature' => 'QA + smoke testing',              'hours_per_month' => 160],
        ];
        $start = $yearStart->copy();
        $end = $yearStart->copy()->addMonths(7)->endOfMonth();
        $months = 8;
        $totalHours = $months * 4 * 160;

        $deal = $this->createWonDeal($tenant, [
            'name' => 'S-Project1 (over-budget OT case)',
            'client' => 'Customer Alpha',
            'budget' => 216_822_096,
            'monthly_fee' => 27_102_762,
            'months' => $months,
            'workload_hours' => $totalHours,
            'team' => $team,
            'start' => $start,
            'end' => $end,
            // No deal_overheads — sheet folds everything into the 15% rate so the
            // system's calc (labor × 1.15) produces the sheet's monthly cost cleanly.
            'overheads' => [],
            'ghost_roles' => [
                ['role_type' => 'pm',      'quantity' => 1, 'months' => $months],
                ['role_type' => 'backend', 'quantity' => 3, 'months' => $months],
            ],
            'ot_policy' => 'absorbed_by_provider',
            'ot_rate' => 35_000,
            'ot_notes' => 'Provider absorbs all OT — profit takes the hit.',
            'admin' => $admin,
        ]);

        [$contract, $project] = $this->createContractAndProject($tenant, $deal, $admin, [
            'contract_number' => 'TEST-CON-2026-001',
            'project_number' => 'TEST-PRJ-2026-101',
            'budget_hours' => $totalHours,
        ]);

        $this->createTeamAssignments($project, $team, $months);
        $this->createMonthlyInvoices($tenant, $contract, $start, [
            ['offset' => 0, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 1, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 2, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 3, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 4, 'amount' => 27_102_762, 'status' => 'Pending'],
        ]);

        // OT placement per sheet: Feb 20h, Mar 60h, Apr 60h, May (partial) 60h.
        // 4-person team → divide hours evenly per member.
        $this->createMonthlyTimeEntries($tenant, $project, $admin, $team, [
            ['month_offset' => 0, 'percent' => 1.00, 'ot_hours_per_member' => 0],   // Jan
            ['month_offset' => 1, 'percent' => 1.00, 'ot_hours_per_member' => 5],   // Feb 20h ÷ 4
            ['month_offset' => 2, 'percent' => 1.00, 'ot_hours_per_member' => 15],  // Mar 60h ÷ 4
            ['month_offset' => 3, 'percent' => 1.00, 'ot_hours_per_member' => 15],  // Apr 60h ÷ 4
            ['month_offset' => 4, 'percent' => 0.60, 'ot_hours_per_member' => 9],   // May 60% logged so far
        ], $start);

        // OT per member per month (must mirror createMonthlyTimeEntries above):
        // surfaces 5+15+15+9 = 44h late hours per member × 4 members = 176h total
        // through May 18 (sheet's 200h target less the un-elapsed May portion).
        $this->seedScheduleHistory($tenant, $project, $team, $start, $months, [
            1 => 5,   // Feb
            2 => 15,  // Mar
            3 => 15,  // Apr
            4 => 9,   // May (partial — today is May 18)
        ]);
    }

    /**
     * S-Project2 — Mar–Dec 2026, 1 Leader + 2 Members. On plan, no OT.
     * Cost override: 105,778,095 total (sheet's 10,577,810 × 10 months).
     */
    private function createSProject2(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart): void
    {
        $team = [
            ['emp' => $employees['leader2'], 'role_code' => 'pm',      'feature' => 'Tech lead', 'hours_per_month' => 160],
            ['emp' => $employees['member4'], 'role_code' => 'backend', 'feature' => 'Backend services', 'hours_per_month' => 160],
            ['emp' => $employees['member5'], 'role_code' => 'backend', 'feature' => 'Frontend + integration', 'hours_per_month' => 160],
        ];
        $start = $yearStart->copy()->addMonths(2);
        $end = $yearStart->copy()->addMonths(11)->endOfMonth();
        $months = 10;
        $totalHours = $months * 3 * 160;

        $deal = $this->createWonDeal($tenant, [
            'name' => 'S-Project2 (on-plan)',
            'client' => 'Customer Beta',
            'budget' => 211_556_190,
            'monthly_fee' => 21_155_619,
            'months' => $months,
            'workload_hours' => $totalHours,
            'team' => $team,
            'start' => $start,
            'end' => $end,
            'overheads' => [],
            'ghost_roles' => [
                ['role_type' => 'pm',      'quantity' => 1, 'months' => $months],
                ['role_type' => 'backend', 'quantity' => 2, 'months' => $months],
            ],
            'ot_policy' => 'no_overtime_allowed',
            'ot_rate' => 0,
            'ot_notes' => 'No OT planned — strict 8h/day schedule.',
            'admin' => $admin,
        ]);

        [$contract, $project] = $this->createContractAndProject($tenant, $deal, $admin, [
            'contract_number' => 'TEST-CON-2026-002',
            'project_number' => 'TEST-PRJ-2026-102',
            'budget_hours' => $totalHours,
        ]);

        $this->createTeamAssignments($project, $team, $months);
        $this->createMonthlyInvoices($tenant, $contract, $start, [
            ['offset' => 0, 'amount' => 21_155_619, 'status' => 'Paid'],
            ['offset' => 1, 'amount' => 21_155_619, 'status' => 'Paid'],
            ['offset' => 2, 'amount' => 21_155_619, 'status' => 'Pending'],
        ]);

        $this->seedScheduleHistory($tenant, $project, $team, $start, $months);
        $this->createMonthlyTimeEntries($tenant, $project, $admin, $team, [
            ['month_offset' => 0, 'percent' => 1.00, 'ot_hours_per_member' => 0],
            ['month_offset' => 1, 'percent' => 1.00, 'ot_hours_per_member' => 0],
            ['month_offset' => 2, 'percent' => 0.60, 'ot_hours_per_member' => 0],
        ], $start);
    }

    /**
     * A-Project1 — Jul–Oct 2026, in negotiation. Full estimation lock-in,
     * no contract/project yet. Cost override: 42,311,238 total.
     */
    private function createAProject1(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart): void
    {
        $team = [
            ['emp' => $employees['leader3'], 'role_code' => 'pm',      'feature' => 'Tech lead', 'hours_per_month' => 160],
            ['emp' => $employees['member6'], 'role_code' => 'backend', 'feature' => 'Backend services', 'hours_per_month' => 160],
            ['emp' => $employees['member7'], 'role_code' => 'backend', 'feature' => 'API + testing', 'hours_per_month' => 160],
        ];
        $months = 4;
        $start = Carbon::create(2026, 7, 1);
        $end = Carbon::create(2026, 10, 31);

        // Estimated cost rollup for the Forecast page (Deal Detail recomputes
        // live from resources, but Forecast reads totalEstimatedCost directly).
        $rollup = $this->rollupCosts($team, $months, 84_622_476);

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'A-Project1 (negotiation)',
            'client' => 'Customer Gamma',
            'contact_name' => 'Mr. Watanabe',
            'contact_email' => 'watanabe@gamma.example.jp',
            'contact_phone' => '+81 90 0000 0000',
            'estimated_value' => 84_622_476,
            'win_probability' => 80,
            'status' => 'negotiation',
            'lifecycle_status' => 'active',
            'expected_close_date' => $start->copy()->subDay()->toDateString(),
            'lead_source' => 'inbound',
            'client_budget' => 84_622_476,
            'timeline_months' => $months,
            'workload_hours' => $months * 3 * 160,
            'workload_description' => 'A-rank deal in negotiation. Project window: 2026/07 – 2026/10. Full estimation lock-in; ready to draft contract.',
            'ot_policy_model' => 'customer_pays_per_hour',
            'ot_rate_per_hour' => 35_000,
            'ot_included_hours_per_month' => 0,
            'ot_notes' => 'All OT billable to customer.',
            'customer_support_obligations' => 'Customer provides test environment + sample data.',
            'out_of_scope_policy' => 'Hardware procurement out of scope.',
            'working_hours' => '09:00 – 18:00 Mon–Fri JST',
            'testing_range' => 'Browser: Chrome + Edge latest. Mobile: not in scope.',
            'target_margin' => 50,
            'base_labor_cost' => $rollup['labor'],
            'overhead_cost' => $rollup['overhead'],
            'buffer_cost' => 0,
            'total_estimated_cost' => $rollup['total'],
            'estimated_gross_profit' => $rollup['profit'],
            'final_monthly_fee' => 21_155_619,
            'final_installation_fee' => 0,
            'final_contract_months' => $months,
            'final_ot_policy' => 'Customer pays per hour at MMK 35,000/hr.',
            'final_support_hours_per_month' => 160,
            'final_team_summary' => '1 Leader + 2 Members, 4-month engagement.',
            'final_currency' => 'MMK',
            // Intentionally no `final_confirmed_at` — Forecast keys off either
            // project.startDate (won deals) or expectedCloseDate (non-won). Setting
            // final_confirmed_at would falsely position this deal in May instead
            // of its real Jul kickoff window.
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $team, $roles, [
            ['role_type' => 'pm',      'quantity' => 1, 'months' => $months],
            ['role_type' => 'backend', 'quantity' => 2, 'months' => $months],
        ], [], $admin);
    }

    /**
     * B-Project1 — Oct–Dec 2026, qualified. Initial estimate done.
     */
    private function createBProject1(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart): void
    {
        $team = [
            ['emp' => $employees['leader3'], 'role_code' => 'pm',      'feature' => 'Tech lead', 'hours_per_month' => 160],
            ['emp' => $employees['member8'], 'role_code' => 'backend', 'feature' => 'Backend services', 'hours_per_month' => 160],
            ['emp' => $employees['member9'], 'role_code' => 'backend', 'feature' => 'API + integration', 'hours_per_month' => 160],
        ];
        $months = 3;
        $start = Carbon::create(2026, 10, 1);
        $end = Carbon::create(2026, 12, 31);

        // Estimated cost rollup for the Forecast page.
        $rollup = $this->rollupCosts($team, $months, 63_466_857);

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'B-Project1 (qualified)',
            'client' => 'Customer Delta',
            'contact_name' => 'Ms. Tanaka',
            'contact_email' => 'tanaka@delta.example.jp',
            'contact_phone' => '+81 90 1111 1111',
            'estimated_value' => 63_466_857,
            'win_probability' => 50,
            'status' => 'qualified',
            'lifecycle_status' => 'active',
            // Close-date is the day before project kickoff (Sep 30 → Oct 1).
            // The forecast bumps close-date by +1 day to derive the start month,
            // so this keeps B-Project1 plotted from Oct, matching its window.
            'expected_close_date' => $start->copy()->subDay()->toDateString(),
            'lead_source' => 'referral',
            'client_budget' => 63_466_857,
            'timeline_months' => $months,
            'workload_hours' => $months * 3 * 160,
            'workload_description' => 'B-rank deal — qualified. Project window: 2026/10 – 2026/12. Initial estimation done; final terms TBD.',
            'ot_policy_model' => 'customer_pays_per_hour',
            'ot_rate_per_hour' => 35_000,
            'ot_included_hours_per_month' => 0,
            'ot_notes' => 'All OT billable.',
            'working_hours' => '09:00 – 18:00 Mon–Fri JST',
            'target_margin' => 50,
            'base_labor_cost' => $rollup['labor'],
            'overhead_cost' => $rollup['overhead'],
            'buffer_cost' => 0,
            'total_estimated_cost' => $rollup['total'],
            'estimated_gross_profit' => $rollup['profit'],
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $team, $roles, [
            ['role_type' => 'pm',      'quantity' => 1, 'months' => $months],
            ['role_type' => 'backend', 'quantity' => 2, 'months' => $months],
        ], [], $admin);
    }

    /**
     * Seeds task/phase assignments + weekly progress logs.
     *
     * $otByMonthOffsetPerMember: optional map [month_offset => OT hours per member
     * for that month]. When provided, the LAST weekly log of each OT month gets
     * `used_hours = progress_hours + OT_hours`, surfacing the gap to the system's
     * Late Hours / OT Impact detector (which reads `used - progress` from
     * phase_progress_logs).
     */
    private function seedScheduleHistory(Tenant $tenant, Project $project, array $team, Carbon $start, int $months, array $otByMonthOffsetPerMember = []): void
    {
        $today = Carbon::now()->startOfDay();
        $totalDays = max(1, (int) round($months * 20));

        $phaseTemplate = [
            ['code' => 'DESIGN', 'name' => '設計 (Design)',          'pct' => 0.10],
            ['code' => 'IMPL',   'name' => '実装 (Implementation)',  'pct' => 0.70],
            ['code' => 'TEST',   'name' => 'テスト (Testing)',       'pct' => 0.20],
        ];

        foreach ($team as $idx => $member) {
            $employee = $member['emp'];
            $allocated = $member['hours_per_month'] * $months;

            $task = ProjectTaskAssignment::create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'row_no' => $idx + 1,
                'function_id' => 'F'.str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT),
                'function_name' => $member['feature'],
                'category' => 'Implementation',
                'offshore' => false,
                // CHECK constraint on Postgres requires Japanese values:
                // '簡単' (easy), '普通' (normal), '難しい' (hard).
                'difficulty' => '普通',
                'total_hours' => $allocated,
            ]);

            $cursor = $start->copy();
            foreach ($phaseTemplate as $order => $phase) {
                $phaseHours = round($allocated * $phase['pct'], 1);
                $phaseDays = max(1, (int) round($totalDays * $phase['pct']));
                $phaseStart = $cursor->copy();
                $phaseEnd = $cursor->copy()->addDays((int) ceil($phaseDays * 7 / 5))->subDay();

                $actualStart = $phaseStart->lte($today) ? $phaseStart : null;
                $actualEnd = $phaseEnd->lte($today) ? $phaseEnd : null;
                $status = match (true) {
                    $phaseEnd->lte($today) => '完了',
                    $phaseStart->lte($today) => '進行中',
                    default => '未着手',
                };

                $phaseAssignment = ProjectTaskPhaseAssignment::create([
                    'tenant_id' => $tenant->id,
                    'task_assignment_id' => $task->id,
                    'phase_code' => $phase['code'],
                    'phase_name' => $phase['name'],
                    'phase_order' => $order + 1,
                    'estimated_hours' => $phaseHours,
                    'start_day_hours' => 8,
                    'assignee_id' => $employee->id,
                    'assignment_source' => 'seed',
                    'planned_start' => $phaseStart->toDateString(),
                    'planned_end' => $phaseEnd->toDateString(),
                    'actual_start' => $actualStart?->toDateString(),
                    'actual_end' => $actualEnd?->toDateString(),
                    'status' => $status,
                ]);

                if ($status !== '未着手') {
                    $endLog = $actualEnd ?? $today;
                    $weeks = max(1, (int) ceil($phaseStart->diffInDays($endLog) / 7));
                    $hoursPerWeek = round($phaseHours / $weeks, 1);
                    $logCursor = $phaseStart->copy();

                    // Track which (employee, month) pairs we've already credited
                    // OT to, so we never double-count when the phase ends mid-month
                    // (last log of phase + last log of month would otherwise both fire).
                    $creditedMonths = [];

                    for ($w = 0; $w < $weeks; $w++) {
                        $logDate = $logCursor->copy()->addDays(4);
                        if ($logDate->gt($endLog)) {
                            $logDate = $endLog->copy();
                        }
                        $isLastInProgress = ($status === '進行中' && $w === $weeks - 1);
                        $hours = $isLastInProgress
                            ? round($hoursPerWeek * 0.6, 1)
                            : $hoursPerWeek;

                        // Detect whether this is the LAST log of its calendar month
                        // for this employee in this phase. Look ahead one week.
                        $peekNextCursor = $logCursor->copy()->addDays(7);
                        $peekNextLogDate = $peekNextCursor->copy()->addDays(4);
                        $loopWillContinue = ! $peekNextCursor->gt($endLog);
                        $nextLogStillInThisMonth = $loopWillContinue
                            && $peekNextLogDate->format('Y-m') === $logDate->format('Y-m');
                        $isLastLogOfMonth = ! $nextLogStillInThisMonth;

                        $monthKey = $logDate->format('Y-m');
                        $logMonthOffset = ($logDate->year - $start->year) * 12 + ($logDate->month - $start->month);
                        $otCredit = (
                            $isLastLogOfMonth
                            && ! isset($creditedMonths[$monthKey])
                        ) ? ($otByMonthOffsetPerMember[$logMonthOffset] ?? 0) : 0;

                        if ($otCredit > 0) {
                            $creditedMonths[$monthKey] = true;
                        }

                        $usedHours = $hours + $otCredit;

                        PhaseProgressLog::create([
                            'tenant_id' => $tenant->id,
                            'phase_assignment_id' => $phaseAssignment->id,
                            'employee_id' => $employee->id,
                            'log_date' => $logDate->toDateString(),
                            'progress_hours' => $hours,
                            'used_hours' => $usedHours,
                            'note' => $otCredit > 0 ? 'Weekly progress + OT for month' : 'Weekly progress',
                        ]);

                        $logCursor->addDays(7);
                        if ($logCursor->gt($endLog)) {
                            break;
                        }
                    }
                }

                $cursor = $phaseEnd->copy()->addDay();
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Build a won deal. If $opts['cost_override'] is set, use those
     * exact rollup values instead of computing from per-employee
     * salaries — this is what makes the demo match the sheet.
     */
    private function createWonDeal(Tenant $tenant, array $opts): Deal
    {
        $team = $opts['team'];

        if (isset($opts['cost_override'])) {
            $rollup = [
                'labor' => $opts['cost_override']['labor'],
                'overhead' => $opts['cost_override']['overhead'],
                'total' => $opts['cost_override']['total'],
                'profit' => $opts['budget'] - $opts['cost_override']['total'],
            ];
        } else {
            $rollup = $this->rollupCosts($team, $opts['months'], $opts['budget']);
        }

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => $opts['name'],
            'client' => $opts['client'],
            'contact_name' => 'Demo Contact',
            'contact_email' => 'contact@'.\Illuminate\Support\Str::slug($opts['client']).'.example.jp',
            'contact_phone' => '+81 90 0000 0000',
            'estimated_value' => $opts['budget'],
            'win_probability' => 100,
            'status' => 'won',
            'lifecycle_status' => 'active',
            'expected_close_date' => $opts['start']->copy()->subDays(7)->toDateString(),
            'lead_source' => 'partner',
            'client_budget' => $opts['budget'],
            'timeline_months' => $opts['months'],
            'workload_hours' => $opts['workload_hours'],
            'workload_description' => $opts['name'].' — won-deal demo (sheet-compliant).',
            'ot_policy_model' => $opts['ot_policy'],
            'ot_rate_per_hour' => $opts['ot_rate'],
            'ot_included_hours_per_month' => 0,
            'ot_notes' => $opts['ot_notes'],
            'customer_support_obligations' => 'Customer provides test env + sample data.',
            'out_of_scope_policy' => 'Hardware procurement out of scope.',
            'working_hours' => '09:00 – 18:00 Mon–Fri JST',
            'testing_range' => 'Browser: Chrome + Edge latest.',
            'target_margin' => 50,
            'base_labor_cost' => $rollup['labor'],
            'overhead_cost' => $rollup['overhead'],
            'buffer_cost' => 0,
            'total_estimated_cost' => $rollup['total'],
            'estimated_gross_profit' => $rollup['profit'],
            'final_monthly_fee' => $opts['monthly_fee'],
            'final_installation_fee' => 0,
            'final_contract_months' => $opts['months'],
            'final_ot_policy' => $opts['ot_notes'],
            'final_support_hours_per_month' => 160,
            'final_team_summary' => count($team).' team members across '.$opts['months'].' months.',
            'final_currency' => 'MMK',
            'final_confirmed_at' => $opts['start']->copy()->subDays(7),
            'won_at' => $opts['start']->copy()->subDays(2),
            'win_reason' => 'Strong reference + competitive pricing.',
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $team, [], $opts['ghost_roles'], $opts['overheads'], $opts['admin']);

        return $deal;
    }

    /**
     * @return array{0: Contract, 1: Project}
     */
    private function createContractAndProject(Tenant $tenant, Deal $deal, User $admin, array $opts): array
    {
        $start = Carbon::parse($deal->expected_close_date)->addDays(7);
        $end = $start->copy()->addMonths($deal->timeline_months)->endOfMonth();

        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'deal_id' => $deal->id,
            'contract_number' => $opts['contract_number'],
            'client' => $deal->client,
            'total_value' => $deal->client_budget,
            'revenue_recognized' => 0,
            'status' => 'Active',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'signed_at' => $start->copy()->subDay(),
            'payment_terms_days' => 7,
            'currency' => 'MMK',
            'notes' => 'Demo Hackathon contract (sheet-compliant).',
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'project_number' => $opts['project_number'],
            'name' => $deal->name,
            'client' => $deal->client,
            'budget_hours' => $opts['budget_hours'],
            'consumed_hours' => 0,
            'status' => 'On Track',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'kickoff_date' => $start->toDateString(),
        ]);

        return [$contract, $project];
    }

    private function createTeamAssignments(Project $project, array $team, int $months): void
    {
        foreach ($team as $member) {
            ProjectTeamAssignment::create([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'employee_id' => $member['emp']->id,
                'allocated_hours' => $member['hours_per_month'] * $months,
                'assignment_source' => 'deal_transfer',
            ]);
        }
    }

    private function createMonthlyInvoices(Tenant $tenant, Contract $contract, Carbon $start, array $rows): void
    {
        $recognized = 0;
        foreach ($rows as $idx => $row) {
            $issue = $start->copy()->addMonths($row['offset'])->day(5);
            $due = $issue->copy()->addDays(7);
            $paid = $row['status'] === 'Paid' ? $issue->copy()->addDays(5) : null;

            Invoice::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'milestone_id' => null,
                'invoice_number' => $contract->contract_number.'-INV-'.str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT),
                'issue_date' => $issue->toDateString(),
                'due_date' => $due->toDateString(),
                'amount' => $row['amount'],
                'tax' => 0,
                'status' => $row['status'],
                'paid_at' => $paid,
                'notes' => 'Monthly fee — '.$issue->format('Y/m'),
            ]);

            if ($row['status'] === 'Paid') {
                $recognized += $row['amount'];
            }
        }
        $contract->update(['revenue_recognized' => $recognized]);
    }

    private function createMonthlyTimeEntries(Tenant $tenant, Project $project, User $admin, array $team, array $months, Carbon $start): void
    {
        $totalApproved = 0;
        foreach ($months as $row) {
            $monthDate = $start->copy()->addMonths($row['month_offset']);
            $logDate = $monthDate->copy()->endOfMonth();
            if ($logDate->isFuture()) {
                $logDate = Carbon::now();
            }
            $normalHours = round(160 * $row['percent'], 1);
            foreach ($team as $member) {
                if ($normalHours > 0) {
                    TimeEntry::create([
                        'tenant_id' => $tenant->id,
                        'project_id' => $project->id,
                        'employee_id' => $member['emp']->id,
                        'approved_by' => $admin->id,
                        'task' => $monthDate->format('Y/m').' — '.$member['feature'],
                        'date' => $logDate->toDateString(),
                        'hours' => $normalHours,
                        'billable' => true,
                        'status' => 'Approved',
                        'approved_at' => $logDate->copy()->addDay(),
                    ]);
                    $totalApproved += $normalHours;
                }
                if ($row['ot_hours_per_member'] > 0) {
                    TimeEntry::create([
                        'tenant_id' => $tenant->id,
                        'project_id' => $project->id,
                        'employee_id' => $member['emp']->id,
                        'approved_by' => $admin->id,
                        'task' => $monthDate->format('Y/m').' — OT: '.$member['feature'],
                        'date' => $logDate->toDateString(),
                        'hours' => $row['ot_hours_per_member'],
                        'billable' => true,
                        'status' => 'Approved',
                        'approved_at' => $logDate->copy()->addDay(),
                        'notes' => 'Overtime — absorbed by provider.',
                    ]);
                    $totalApproved += $row['ot_hours_per_member'];
                }
            }
        }
        $project->update(['consumed_hours' => $totalApproved]);

        $contract = $project->contract;
        $newStatus = $project->computeAutoStatus(
            (float) $project->budget_hours,
            (float) $totalApproved,
            $contract?->status,
        );
        if ($newStatus !== null && $newStatus !== $project->status) {
            $project->update(['status' => $newStatus]);
        }
    }

    private function rollupCosts(array $team, int $months, float $clientBudget): array
    {
        $labor = 0.0;
        foreach ($team as $member) {
            $emp = $member['emp'];
            $monthly = (float) $emp->monthly_salary;
            $labor += $monthly * $months * 1.0;
        }
        $overhead = $labor * (self::OVERHEAD_PCT / 100);
        $total = $labor + $overhead;

        return [
            'labor' => round($labor, 2),
            'overhead' => round($overhead, 2),
            'total' => round($total, 2),
            'profit' => round($clientBudget - $total, 2),
        ];
    }

    private function seedDealChildren(
        Tenant $tenant,
        Deal $deal,
        array $team,
        array $roles,
        array $ghostRoles,
        array $overheads,
        User $admin,
    ): void {
        foreach ($ghostRoles as $row) {
            $range = $this->salaryRange($team, $row['role_type']);
            DealGhostRole::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'role_type' => $row['role_type'],
                'quantity' => $row['quantity'],
                'months' => $row['months'],
                'avg_monthly_salary' => round(($range['min'] + $range['max']) / 2, 2),
                'min_monthly_salary' => $range['min'],
                'max_monthly_salary' => $range['max'],
            ]);
        }

        foreach ($team as $member) {
            DealHardAssignment::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'employee_id' => $member['emp']->id,
                'allocated_hours' => $member['hours_per_month'] * $deal->timeline_months,
            ]);
        }

        foreach ($team as $member) {
            EstimationResource::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'job_role_id' => $member['emp']->job_role_id,
                'role_id' => $member['emp']->job_role_id,
                'feature_name' => $member['feature'],
                'hours' => $member['hours_per_month'] * $deal->timeline_months,
                'employee_id' => $member['emp']->id,
            ]);
        }

        foreach ($overheads as $overhead) {
            DealOverhead::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'name' => $overhead['name'],
                'cost' => $overhead['cost'],
            ]);
        }

        EstimationVersion::create([
            'tenant_id' => $tenant->id,
            'deal_id' => $deal->id,
            'version_number' => 1,
            'resources' => array_map(fn ($m) => [
                'roleId' => $m['emp']->job_role_id,
                'featureName' => $m['feature'],
                'hours' => $m['hours_per_month'] * $deal->timeline_months,
                'employeeId' => $m['emp']->id,
            ], $team),
            'overheads' => array_map(fn ($o) => [
                'name' => $o['name'],
                'cost' => $o['cost'],
            ], $overheads),
            'target_margin' => $deal->target_margin,
            'notes' => 'Seeded estimate — Demo Hackathon (sheet-compliant).',
            'created_by' => $admin->id,
            'created_at' => $deal->created_at?->copy()->subDays(7) ?? Carbon::now()->subDays(7),
        ]);
    }

    private function salaryRange(array $team, string $capacityRole): array
    {
        $matches = collect($team)
            ->map(fn ($m) => $m['emp'])
            ->filter(fn (Employee $e) => $e->capacity_role === $capacityRole);

        if ($matches->isEmpty()) {
            return ['min' => 0, 'max' => 0];
        }

        return [
            'min' => (float) $matches->min('monthly_salary'),
            'max' => (float) $matches->max('monthly_salary'),
        ];
    }
}
