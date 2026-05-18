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
use App\Models\Milestone;
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
 * Hackathon demo seeder — mirrors the "Sample Test Data-2" sheet of
 * the System Flow workbook the team prepared for judging.
 *
 * Budget Year 2026/1–12, MMK, 15 employees, 4 projects (2× S-rank
 * won, 1× A-rank in negotiation, 1× B-rank qualified). S-Project1
 * runs over budget with 200h of OT spread across Apr–Jul to
 * demonstrate the OT / over-budget flow; S-Project2 stays on plan.
 *
 * Idempotent — wipes prior "hackthon-demo" rows and recreates them.
 * Run with:
 *
 *   php artisan db:seed --class=HackthonSeeder
 */
class HackthonSeeder extends Seeder
{
    private const SLUG = 'brycen-myanmar';
    private const PASSWORD = 'Demo@1234';
    private const EMAIL_DOMAIN = 'brycenmyanmar.com.mm';
    private const OVERHEAD_PCT = 15;
    private const SELL_MULTIPLIER = 2;

    public function run(): void
    {
        Model::unguarded(function () {
            $this->wipeExisting();

            $tenant = Tenant::create([
                'name' => 'Brycen Myanmar',
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

            // 2026/01/01 anchors the demo so re-seeding yields the same
            // project windows regardless of when run.
            $yearStart = Carbon::create(2026, 1, 1);

            $this->createSProject1($tenant, $employees, $roles, $admin, $yearStart);
            $this->createSProject2($tenant, $employees, $roles, $admin, $yearStart);
            $this->createAProject1($tenant, $employees, $roles, $admin, $yearStart);
            $this->createBProject1($tenant, $employees, $roles, $admin, $yearStart);

            $this->command->info('Brycen Myanmar tenant seeded.');
            $this->command->info('  Tenant ID: '.$tenant->id);
            $this->command->info('  Logins (password = '.self::PASSWORD.'):');
            foreach ($users as $u) {
                $this->command->info('    '.str_pad($u->app_role, 10).' '.$u->email);
            }
        });
    }

    private function wipeExisting(): void
    {
        // Match the current slug and any legacy slugs this seeder used
        // before, so re-running cleans up everything it ever created.
        $tenants = Tenant::whereIn('slug', [self::SLUG, 'hackthon-demo'])->get();
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

    /**
     * Rate is the billable hourly rate used by Estimation when an
     * employee can't be matched directly. Set to roughly leader/member
     * sell-price per hour in the workbook.
     */
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

    /**
     * 15 employees keyed for downstream lookup. Salary numbers come
     * straight from the "Sample Test Data-2" sheet (basic / allowance
     * split). Workable hours = 160 (JP convention; matches sheet's
     * "Hour 1 to 160" note).
     */
    private function createEmployees(
        Tenant $tenant,
        array $departments,
        array $roles,
        array $capacityRoles,
        array $ranks,
    ): array {
        $rows = [
            ['key' => 'leader1', 'name' => 'Leader1', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',      'rank' => 'Lead',   'basic' => 4_000_000, 'allow' => 50_000],
            ['key' => 'leader2', 'name' => 'Leader2', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',      'rank' => 'Lead',   'basic' => 4_000_000, 'allow' => 30_000],
            ['key' => 'leader3', 'name' => 'Leader3', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',      'rank' => 'Lead',   'basic' => 4_000_000, 'allow' => 0],
            ['key' => 'member1', 'name' => 'Member1', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 50_000],
            ['key' => 'member2', 'name' => 'Member2', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 30_000],
            ['key' => 'member3', 'name' => 'Member3', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 20_000],
            ['key' => 'member4', 'name' => 'Member4', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member5', 'name' => 'Member5', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member6', 'name' => 'Member6', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member7', 'name' => 'Member7', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'qa',       'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member8', 'name' => 'Member8', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member9', 'name' => 'Member9', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
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
                'workable_hours' => 160,
                'status' => 'Active',
            ])->fresh();
        }

        return $employees;
    }

    /**
     * One User per employee. app_role mapping:
     *   - leader1            → Admin (the demo's "owner" account)
     *   - leader2, leader3   → Executive (IT department leadership)
     *   - IT Members         → Delivery (time-tracking / schedule pages)
     *   - Sales member       → Sales
     *   - HR members         → HR
     * Email format: <employee_key>@brycenmyanmar.com.mm
     * Password   : Demo@1234 for every account.
     *
     * @return array<string, User>  keyed by employee key for re-lookup
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
        // Workbook constants:
        //   - overhead 15% on salary
        //   - sell = cost × 2  →  cost_to_bill_ratio = 0.50
        //   - yearly target profit 120,000,000 MMK
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

    /**
     * Common skills used by the AI Team Builder + Estimation flow.
     * Categories: Technical / Management.
     */
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

    /**
     * Attach skill rows per employee. IT engineers get a stack-y
     * mix; leaders get management skills; Sales/HR get a token
     * "Client Relations" skill so they aren't empty.
     */
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

    /**
     * Seed JP public holidays (元日, 建国記念の日, Happy Mondays,
     * equinoxes) for the current year and the next two. Mirrors
     * what JapanPublicHolidaysSeeder produces but scoped to this
     * tenant only, so re-running the standalone seeder doesn't
     * matter.
     */
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

            // Happy Monday national holidays.
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

            // Equinoxes (NAOJ table, 2024-2030 hardcoded).
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

    // ── Projects ───────────────────────────────────────────────────────

    /**
     * S-Project1 — Jan–Jun 2026, 1 Leader + 3 Members. Sequential
     * with S-Project2 (Jul–Dec) so no employee can be on both at
     * once. Demonstrates the OT / over-budget scenario:
     *   - ~150 OT hours spread Mar–Jun.
     *   - OT absorbed by provider → ⑦ Profit Calculate subtracts cost.
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
        $end = $yearStart->copy()->addMonths(5)->endOfMonth(); // Jun 30
        $months = 6;
        $totalHours = $months * 4 * 160; // 3840 baseline hours

        $deal = $this->createWonDeal($tenant, [
            'name' => 'S-Project1 (over-budget OT case)',
            'client' => 'Customer Alpha',
            'budget' => 162_616_572, // monthly_fee × 6
            'monthly_fee' => 27_102_762,
            'months' => $months,
            'workload_hours' => $totalHours,
            'team' => $team,
            'start' => $start,
            'end' => $end,
            'overheads' => [
                ['name' => 'Cloud infra', 'cost' => 3_000_000],
            ],
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
            'contract_number' => 'HACK-CON-2026-001',
            'project_number' => 'HACK-PRJ-2026-101',
            'budget_hours' => $totalHours,
        ]);

        // Jan-Apr paid, May pending. (Today is 2026-05-18, project
        // ends Jun so one invoice remains beyond today.)
        $this->createMonthlyInvoices($tenant, $contract, $start, [
            ['offset' => 0, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 1, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 2, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 3, 'amount' => 27_102_762, 'status' => 'Paid'],
            ['offset' => 4, 'amount' => 27_102_762, 'status' => 'Pending'],
        ]);

        // Team / time-entry / task-phase / progress-log seeding all
        // rolled back per request — demoers walk through the AI
        // Assign Team → AI Task Assignment → time-logging flows live.
    }

    /**
     * S-Project2 — Jul–Dec 2026, 1 Leader + 2 Members. Starts
     * after S-Project1 ends so no team-overlap is possible.
     * On plan, no OT, hasn't started yet as of today (May 18).
     */
    private function createSProject2(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart): void
    {
        $team = [
            ['emp' => $employees['leader2'], 'role_code' => 'pm',      'feature' => 'Tech lead', 'hours_per_month' => 160],
            ['emp' => $employees['member4'], 'role_code' => 'backend', 'feature' => 'Backend services', 'hours_per_month' => 160],
            ['emp' => $employees['member5'], 'role_code' => 'backend', 'feature' => 'Frontend + integration', 'hours_per_month' => 160],
        ];
        $start = $yearStart->copy()->addMonths(6); // July 1
        $end = $yearStart->copy()->addMonths(11)->endOfMonth(); // Dec 31
        $months = 6;
        $totalHours = $months * 3 * 160; // 2880

        $deal = $this->createWonDeal($tenant, [
            'name' => 'S-Project2 (on-plan)',
            'client' => 'Customer Beta',
            'budget' => 126_933_714, // monthly_fee × 6
            'monthly_fee' => 21_155_619,
            'months' => $months,
            'workload_hours' => $totalHours,
            'team' => $team,
            'start' => $start,
            'end' => $end,
            'overheads' => [
                ['name' => 'Cloud infra', 'cost' => 2_000_000],
            ],
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
            'contract_number' => 'HACK-CON-2026-002',
            'project_number' => 'HACK-PRJ-2026-102',
            'budget_hours' => $totalHours,
        ]);

        // Project starts Jul 1 — no invoices issued yet as of today
        // (May 18). Contract status set to Signed, not Active, since
        // the start_date is still in the future.
        $contract->update(['status' => 'Signed']);

        // Team / task-phase / progress-log seeding all rolled back.
    }

    /**
     * A-Project1 — Jul–Oct 2026, in negotiation. Has full
     * estimation lock-in but no contract or project yet (the
     * win_deal stored procedure would create those on win).
     */
    private function createAProject1(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart): void
    {
        $team = [
            ['emp' => $employees['leader3'], 'role_code' => 'pm',      'feature' => 'Tech lead', 'hours_per_month' => 160],
            ['emp' => $employees['member6'], 'role_code' => 'backend', 'feature' => 'Backend services', 'hours_per_month' => 160],
            ['emp' => $employees['member7'], 'role_code' => 'backend', 'feature' => 'API + testing', 'hours_per_month' => 160],
        ];
        $months = 4;
        // Project window: 2026/7/1 → 2026/10/31. Anchor the deal's
        // expected_close_date to 2026/6/30 so the implied kick-off
        // date reads as "starts July 2026" on the detail page.
        $start = Carbon::create(2026, 7, 1);
        $end = Carbon::create(2026, 10, 31);

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
            'final_monthly_fee' => 21_155_619,
            'final_installation_fee' => 0,
            'final_contract_months' => $months,
            'final_ot_policy' => 'Customer pays per hour at MMK 35,000/hr.',
            'final_support_hours_per_month' => 160,
            'final_team_summary' => '1 Leader + 2 Members, 4-month engagement.',
            'final_currency' => 'MMK',
            'final_confirmed_at' => Carbon::now()->subDays(2),
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $team, $roles, [
            ['role_type' => 'pm',      'quantity' => 1, 'months' => $months],
            ['role_type' => 'backend', 'quantity' => 2, 'months' => $months],
        ], [
            ['name' => 'Cloud infra reserve', 'cost' => 1_500_000],
        ], $admin);
    }

    /**
     * B-Project1 — Oct–Dec 2026, qualified. Estimation rows
     * + ghost roles present but no final_* lock-in yet.
     */
    private function createBProject1(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart): void
    {
        $team = [
            ['emp' => $employees['leader3'], 'role_code' => 'pm',      'feature' => 'Tech lead', 'hours_per_month' => 160],
            ['emp' => $employees['member8'], 'role_code' => 'backend', 'feature' => 'Backend services', 'hours_per_month' => 160],
            ['emp' => $employees['member9'], 'role_code' => 'backend', 'feature' => 'API + integration', 'hours_per_month' => 160],
        ];
        $months = 3;
        // Project window: 2026/10/1 → 2026/12/31. Close window is
        // roughly mid-Sept so it reads as "starts October 2026".
        $start = Carbon::create(2026, 10, 1);
        $end = Carbon::create(2026, 12, 31);

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
            'expected_close_date' => $start->copy()->subDays(15)->toDateString(),
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
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $team, $roles, [
            ['role_type' => 'pm',      'quantity' => 1, 'months' => $months],
            ['role_type' => 'backend', 'quantity' => 2, 'months' => $months],
        ], [
            ['name' => 'Cloud infra reserve', 'cost' => 1_000_000],
        ], $admin);
    }

    /**
     * Seed task + phase assignments + progress logs so the Schedule
     * Tracking page renders realistic history for each won project.
     *
     * Key invariants:
     *   - Weekly progress hours are pinned to the PLANNED weekly
     *     rate (phaseHours / plannedWeeks). This keeps "Progress
     *     Status" (= progress − expected) near 0 instead of running
     *     1000+ hours ahead because we'd been front-loading the
     *     whole phase across the elapsed period.
     *   - OT is injected as `used_hours > progress_hours` excess on
     *     the Implementation phase, divided across the most recent
     *     ~6 weekly logs. So Extra Hours == Σ max(0, used - progress)
     *     surfaces the project-level OT total on the rollup card.
     *
     * @param  float  $otHoursPerMember  total OT hours each team member
     *                                   should accumulate via excess
     *                                   `used_hours`. For S-Project1
     *                                   pass 50 (= 200h ÷ 4 members).
     */
    private function seedScheduleHistory(
        Tenant $tenant,
        Project $project,
        array $team,
        Carbon $start,
        int $months,
        float $otHoursPerMember = 0,
    ): void {
        $today = Carbon::now()->startOfDay();
        $totalWorkdays = max(1, (int) round($months * 20));

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
                $phaseWorkdays = max(1, (int) round($totalWorkdays * $phase['pct']));
                $phaseCalendarDays = (int) ceil($phaseWorkdays * 7 / 5); // 5 workdays → 7 cal-days
                $phaseStart = $cursor->copy();
                $phaseEnd = $cursor->copy()->addDays($phaseCalendarDays)->subDay();

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

                if ($status === '未着手') {
                    $cursor = $phaseEnd->copy()->addDay();
                    continue;
                }

                // Distribute progress at the PLANNED weekly rate so an
                // in-progress phase shows progress matching elapsed time.
                $plannedWeeks = max(1, (int) ceil($phaseStart->diffInDays($phaseEnd) / 7));
                $weeklyPlanned = round($phaseHours / $plannedWeeks, 2);

                $endLog = $actualEnd ?? $today;
                $loggedHours = 0;
                $logCursor = $phaseStart->copy();
                $createdLogs = [];

                while ($logCursor->lte($endLog) && $loggedHours < $phaseHours) {
                    $logDate = $logCursor->copy()->addDays(4); // Fri
                    if ($logDate->gt($endLog)) {
                        $logDate = $endLog->copy();
                    }
                    $hours = min($weeklyPlanned, $phaseHours - $loggedHours);
                    if ($hours <= 0) {
                        break;
                    }
                    $createdLogs[] = PhaseProgressLog::create([
                        'tenant_id' => $tenant->id,
                        'phase_assignment_id' => $phaseAssignment->id,
                        'employee_id' => $employee->id,
                        'log_date' => $logDate->toDateString(),
                        'progress_hours' => $hours,
                        'used_hours' => $hours,
                        'note' => 'Weekly progress',
                    ]);
                    $loggedHours += $hours;
                    $logCursor->addDays(7);
                }

                // Inject OT as excess `used_hours` on the Implementation
                // phase. Spread over the most recent few weekly logs so
                // it lines up with the Apr/May OT bursts.
                if ($otHoursPerMember > 0 && $phase['code'] === 'IMPL' && count($createdLogs) > 0) {
                    $applyToLast = min(count($createdLogs), 6);
                    $perLog = round($otHoursPerMember / $applyToLast, 2);
                    $remaining = $otHoursPerMember;
                    for ($i = count($createdLogs) - $applyToLast; $i < count($createdLogs); $i++) {
                        if ($i < 0) {
                            continue;
                        }
                        $share = $i === count($createdLogs) - 1
                            ? round($remaining, 2) // last log absorbs any rounding drift
                            : $perLog;
                        $log = $createdLogs[$i];
                        $log->update([
                            'used_hours' => round($log->used_hours + $share, 2),
                            'note' => 'Weekly progress + OT',
                        ]);
                        $remaining -= $share;
                    }
                }

                $cursor = $phaseEnd->copy()->addDay();
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Build a won deal with its ghost roles, hard assignments,
     * estimation resources, deal overheads, and a snapshot
     * EstimationVersion. Contract + Project are created separately.
     */
    private function createWonDeal(Tenant $tenant, array $opts): Deal
    {
        $team = $opts['team'];
        $rollup = $this->rollupCosts($team, $opts['months'], $opts['budget']);

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
            'workload_description' => $opts['name'].' — won-deal demo for the hackathon system flow.',
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
        // Span exactly N months — last day is endOfMonth of the
        // Nth month (so a 6-month project starting Jan 1 ends Jun 30,
        // not Jul 31 from a naive addMonths(N)).
        $end = $start->copy()->addMonths($deal->timeline_months - 1)->endOfMonth();

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
            'notes' => 'Hackathon demo contract.',
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

    /**
     * Approved time entries for each member per month. Each row in
     * $months is { month_offset, percent (0..1), ot_hours_per_member }.
     * One time entry per (member, month) summarising hours, plus a
     * separate row for OT when present.
     */
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

        // Re-derive project status from consumed vs budget.
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
            $labor += $monthly * $months * 1.0; // one full month per member per month
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

    /**
     * Writes ghost roles, hard assignments, estimation resources,
     * deal overheads, and a baseline EstimationVersion snapshot.
     */
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
            // Both columns hold the Role UUID — the frontend's estimation
            // simulator resolves `role_id` against the /roles list, so a
            // capacity code (e.g. 'pm'/'backend') here would render as
            // 'Unknown Role'.
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
            'notes' => 'Seeded estimate — Hackathon demo.',
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
