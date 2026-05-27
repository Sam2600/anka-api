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
use App\Models\Project;
use App\Models\ProjectTaskAssignment;
use App\Models\ProjectTaskPhaseAssignment;
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
 * Hackathon V2 demo seeder — built from Demo_DataSeed_Anka.xlsx.
 *
 * Budget Year 2026, MMK, 15 employees, 4 projects:
 *   S1: Wayne Enterprise chatbot sys         Jan–Aug (1L+2M core, +1M Feb–Jun)
 *   S2: Lux Luthor corporation ticket mgmt   Mar–Dec (1L+2M)
 *   A1: Manchester United project             Jul–Oct (negotiation, deal only)
 *   B1: Arsenal Fc project                   Oct–Dec (qualified, deal only)
 *
 * Full schedule history seeded with on-track pattern (zero late hours).
 *
 * Idempotent — wipes both 'brycen-myanmar-v2' AND 'brycen-myanmar' (V1)
 * because they share the same email addresses.
 *
 *   php artisan db:seed --class=HackthonSeederV2
 */
class HackthonSeederV2 extends Seeder
{
    private const SLUG = 'brycen-myanmar-v2';
    private const PASSWORD = 'Demo@1234';
    private const EMAIL_DOMAIN = 'brycenmyanmar.com.mm';
    private const OVERHEAD_PCT = 15;
    private const SELL_MULTIPLIER = 2;

    // Sheet Q16: =SUM(L18:L26)/9 — average of all 9 IT members.
    private const MEMBER_COST_DIVISOR = 9;

    public function run(): void
    {
        Model::unguarded(function () {
            $this->wipeExisting();

            $tenant = Tenant::create([
                'name' => 'Brycen Myanmar V2',
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
            $rankAvg = $this->computeRankAverages($employees);

            $this->createWayneEnterprise($tenant, $employees, $roles, $admin, $yearStart, $rankAvg);
            $this->createLuxLuthor($tenant, $employees, $roles, $admin, $yearStart, $rankAvg);
            $this->createManchesterUnited($tenant, $employees, $roles, $admin, $yearStart, $rankAvg);
            $this->createArsenalFc($tenant, $employees, $roles, $admin, $yearStart, $rankAvg);

            $this->command->info('Brycen Myanmar V2 tenant seeded.');
            $this->command->info('  Tenant ID: '.$tenant->id);
            $this->command->info('  Logins (password = '.self::PASSWORD.'):');
            foreach ($users as $u) {
                $this->command->info('    '.str_pad($u->app_role, 10).' '.$u->email);
            }
        });
    }

    // ── Wipe ──────────────────────────────────────────────────────────

    private function wipeExisting(): void
    {
        $tenants = Tenant::whereIn('slug', [self::SLUG, 'brycen-myanmar'])->get();
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

    // ── Tenant infrastructure ─────────────────────────────────────────

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
        foreach ([
            ['name' => 'IT',    'delivery' => true],
            ['name' => 'Sales', 'delivery' => false],
            ['name' => 'HR',    'delivery' => false],
        ] as $row) {
            $deps[$row['name']] = Department::create([
                'tenant_id' => $tenant->id,
                'name' => $row['name'],
                'headcount' => 0,
                'is_delivery_eligible' => $row['delivery'],
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
            ['key' => 'leader1', 'name' => 'Leader1', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',       'rank' => 'Lead',   'basic' => 4_000_000, 'allow' => 50_000],
            ['key' => 'leader2', 'name' => 'Leader2', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',       'rank' => 'Lead',   'basic' => 4_000_000, 'allow' => 30_000],
            ['key' => 'leader3', 'name' => 'Leader3', 'role' => 'IT Leader', 'dep' => 'IT', 'cap' => 'pm',       'rank' => 'Lead',   'basic' => 4_000_000, 'allow' => 0],
            ['key' => 'member1', 'name' => 'Member1', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 50_000],
            ['key' => 'member2', 'name' => 'Member2', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 30_000],
            ['key' => 'member3', 'name' => 'Member3', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 20_000],
            ['key' => 'member4', 'name' => 'Member4', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member5', 'name' => 'Member5', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member6', 'name' => 'Member6', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member7', 'name' => 'Member7', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'qa',       'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member8', 'name' => 'Member8', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'backend',  'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member9', 'name' => 'Member9', 'role' => 'IT Member', 'dep' => 'IT', 'cap' => 'frontend', 'rank' => 'Senior', 'basic' => 2_000_000, 'allow' => 0],
            ['key' => 'member10','name' => 'Member10','role' => 'Sales',     'dep' => 'Sales','cap' => 'pm',     'rank' => 'Mid',    'basic' => 1_000_000, 'allow' => 0],
            ['key' => 'member11','name' => 'Member11','role' => 'HR',        'dep' => 'HR',   'cap' => 'pm',     'rank' => 'Mid',    'basic' => 1_000_000, 'allow' => 0],
            ['key' => 'member12','name' => 'Member12','role' => 'HR',        'dep' => 'HR',   'cap' => 'pm',     'rank' => 'Junior', 'basic' => 700_000,   'allow' => 0],
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
            'annual_initial_budget' => 100_000_000,
            'employer_tax_percentage' => 0,
            'benefits_percentage' => 0,
            'cost_to_bill_ratio' => 1 / self::SELL_MULTIPLIER,
            'default_monthly_capacity_hours' => 160,
            'fallback_hourly_cost' => 12_500,
        ]);

        InitialBudget::create([
            'tenant_id' => $tenant->id,
            'fiscal_year' => 2026,
            'amount' => 100_000_000,
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

    // ── Projects ───────────────────────────────────────────────────────

    /**
     * S-Project1: Wayne Enterprise chatbot sys — Jan–Aug 2026.
     * Variable team: Jan 1L+2M, Feb–Jun 1L+3M, Jul–Aug 1L+2M.
     * member3 (frontend) only works Feb–Jun (5 months, 800h).
     */
    private function createWayneEnterprise(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart, array $rankAvg): void
    {
        $coreTeam = [
            ['emp' => $employees['leader1'], 'role_code' => 'pm',      'feature' => 'Tech lead + delivery oversight', 'hours_per_month' => 160, 'months' => 8],
            ['emp' => $employees['member1'], 'role_code' => 'backend', 'feature' => 'Core backend services',          'hours_per_month' => 160, 'months' => 8],
            ['emp' => $employees['member2'], 'role_code' => 'backend', 'feature' => 'Integration layer',              'hours_per_month' => 160, 'months' => 8],
        ];
        $extraMember = [
            'emp' => $employees['member3'], 'role_code' => 'frontend', 'feature' => 'Frontend UI + chatbot interface', 'hours_per_month' => 160, 'months' => 5,
        ];
        $fullTeam = array_merge($coreTeam, [$extraMember]);

        $start = $yearStart->copy(); // Jan 1
        $end = $yearStart->copy()->addMonths(7)->endOfMonth(); // Aug 31
        $months = 8;
        $totalHours = (3 * 160 * 8) + (1 * 160 * 5); // 4640h

        // Monthly team composition from sheet: Jan 1L+2M, Feb–Jun 1L+3M, Jul–Aug 1L+2M
        $monthlyTeam = [
            ['leaders' => 1, 'members' => 2], // Jan
            ['leaders' => 1, 'members' => 3], // Feb
            ['leaders' => 1, 'members' => 3], // Mar
            ['leaders' => 1, 'members' => 3], // Apr
            ['leaders' => 1, 'members' => 3], // May
            ['leaders' => 1, 'members' => 3], // Jun
            ['leaders' => 1, 'members' => 2], // Jul
            ['leaders' => 1, 'members' => 2], // Aug
        ];
        $costs = $this->projectCosts($monthlyTeam, $rankAvg);

        $deal = $this->createWonDeal($tenant, [
            'name' => 'Wayne Enterprise chatbot sys',
            'client' => 'Wayne Enterprise',
            'costs' => $costs,
            'monthly_fee' => round($costs['total_income'] / $months, 0),
            'months' => $months,
            'workload_hours' => $totalHours,
            'team' => $fullTeam,
            'start' => $start,
            'end' => $end,
            'overheads' => [],
            'ghost_roles' => [
                ['role_type' => 'pm',       'quantity' => 1, 'months' => 8],
                ['role_type' => 'backend',  'quantity' => 2, 'months' => 8],
                ['role_type' => 'frontend', 'quantity' => 1, 'months' => 5],
            ],
            'ot_policy' => 'no_overtime_allowed',
            'ot_rate' => 0,
            'ot_notes' => 'No OT planned.',
            'admin' => $admin,
        ]);

        [$contract, $project] = $this->createContractAndProject($tenant, $deal, $admin, [
            'contract_number' => 'V2-CON-2026-001',
            'project_number' => 'V2-PRJ-2026-101',
            'budget_hours' => $totalHours,
        ]);

        foreach ($coreTeam as $member) {
            ProjectTeamAssignment::create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'employee_id' => $member['emp']->id,
                'allocated_hours' => 160 * 8,
                'assignment_source' => 'deal_transfer',
            ]);
        }
        ProjectTeamAssignment::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'employee_id' => $extraMember['emp']->id,
            'allocated_hours' => 160 * 5,
            'assignment_source' => 'deal_transfer',
        ]);

        // Invoice amounts derived from per-month income (cost × SELL_MULTIPLIER)
        $today = Carbon::now()->startOfDay();
        $invoiceRows = [];
        foreach ($costs['monthly_costs'] as $m => $monthlyCost) {
            $monthDate = $start->copy()->addMonths($m);
            $isPast = $monthDate->copy()->endOfMonth()->lte($today);
            $invoiceRows[] = [
                'offset' => $m,
                'amount' => round($monthlyCost * self::SELL_MULTIPLIER, 0),
                'status' => $isPast ? 'Paid' : 'Pending',
            ];
        }
        $this->createMonthlyInvoices($tenant, $contract, $start, $invoiceRows);

        $this->createMonthlyTimeEntriesForTeam($tenant, $project, $admin, $fullTeam, $start, $months);

        $this->seedScheduleHistory($tenant, $project, $coreTeam, $start, 8);
        $extraStart = Carbon::create(2026, 2, 1);
        $this->seedScheduleHistory($tenant, $project, [$extraMember], $extraStart, 5);
    }

    /**
     * S-Project2: Lux Luthor corporation ticket management sys — Mar–Dec 2026.
     * Constant team: 1L + 2M for 10 months.
     */
    private function createLuxLuthor(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart, array $rankAvg): void
    {
        $team = [
            ['emp' => $employees['leader2'], 'role_code' => 'pm',      'feature' => 'Tech lead + client governance', 'hours_per_month' => 160, 'months' => 10],
            ['emp' => $employees['member4'], 'role_code' => 'backend', 'feature' => 'Ticket engine + API',           'hours_per_month' => 160, 'months' => 10],
            ['emp' => $employees['member5'], 'role_code' => 'frontend','feature' => 'Dashboard + workflow UI',       'hours_per_month' => 160, 'months' => 10],
        ];
        $start = Carbon::create(2026, 3, 1);
        $end = Carbon::create(2026, 12, 31);
        $months = 10;
        $totalHours = $months * 3 * 160; // 4800h

        // 1L+2M constant for 10 months
        $monthlyTeam = array_fill(0, $months, ['leaders' => 1, 'members' => 2]);
        $costs = $this->projectCosts($monthlyTeam, $rankAvg);

        $deal = $this->createWonDeal($tenant, [
            'name' => 'Lux Luthor corporation ticket management sys',
            'client' => 'Lux Luthor Corporation',
            'costs' => $costs,
            'monthly_fee' => round($costs['total_income'] / $months, 0),
            'months' => $months,
            'workload_hours' => $totalHours,
            'team' => $team,
            'start' => $start,
            'end' => $end,
            'overheads' => [],
            'ghost_roles' => [
                ['role_type' => 'pm',       'quantity' => 1, 'months' => $months],
                ['role_type' => 'backend',  'quantity' => 1, 'months' => $months],
                ['role_type' => 'frontend', 'quantity' => 1, 'months' => $months],
            ],
            'ot_policy' => 'no_overtime_allowed',
            'ot_rate' => 0,
            'ot_notes' => 'No OT planned — strict 8h/day schedule.',
            'admin' => $admin,
        ]);

        [$contract, $project] = $this->createContractAndProject($tenant, $deal, $admin, [
            'contract_number' => 'V2-CON-2026-002',
            'project_number' => 'V2-PRJ-2026-102',
            'budget_hours' => $totalHours,
        ]);

        $this->createTeamAssignments($project, $team, $months);

        $today = Carbon::now()->startOfDay();
        $invoiceRows = [];
        foreach ($costs['monthly_costs'] as $m => $monthlyCost) {
            $monthDate = $start->copy()->addMonths($m);
            $isPast = $monthDate->copy()->endOfMonth()->lte($today);
            $invoiceRows[] = [
                'offset' => $m,
                'amount' => round($monthlyCost * self::SELL_MULTIPLIER, 0),
                'status' => $isPast ? 'Paid' : 'Pending',
            ];
        }
        $this->createMonthlyInvoices($tenant, $contract, $start, $invoiceRows);

        $this->createMonthlyTimeEntriesForTeam($tenant, $project, $admin, $team, $start, $months);
        $this->seedScheduleHistory($tenant, $project, $team, $start, $months);
    }

    /**
     * A-Project1: Manchester United project — Jul–Oct 2026.
     * Negotiation stage: deal + estimation only, no contract/project.
     */
    private function createManchesterUnited(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart, array $rankAvg): void
    {
        $team = [
            ['emp' => $employees['leader3'], 'role_code' => 'pm',      'feature' => 'Tech lead',       'hours_per_month' => 160, 'months' => 4],
            ['emp' => $employees['member6'], 'role_code' => 'backend', 'feature' => 'Backend services', 'hours_per_month' => 160, 'months' => 4],
            ['emp' => $employees['member7'], 'role_code' => 'qa',      'feature' => 'QA + testing',    'hours_per_month' => 160, 'months' => 4],
        ];
        $months = 4;
        $start = Carbon::create(2026, 7, 1);

        // 1L+2M for 4 months
        $monthlyTeam = array_fill(0, $months, ['leaders' => 1, 'members' => 2]);
        $costs = $this->projectCosts($monthlyTeam, $rankAvg);

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'Manchester United project',
            'client' => 'Manchester United FC',
            'contact_name' => 'Mr. Ferguson',
            'contact_email' => 'ferguson@manutd.example.com',
            'contact_phone' => '+44 161 000 0000',
            'estimated_value' => $costs['total_income'],
            'win_probability' => 80,
            'status' => 'negotiation',
            'lifecycle_status' => 'active',
            'expected_close_date' => $start->copy()->subDay()->toDateString(),
            'lead_source' => 'inbound',
            'client_budget' => $costs['total_income'],
            'timeline_months' => $months,
            'workload_hours' => $months * 3 * 160,
            'workload_description' => 'Fan engagement platform with match-day analytics, membership portal, and real-time notifications. Project window: 2026/07 – 2026/10.',
            'ot_policy_model' => 'customer_pays_per_hour',
            'ot_rate_per_hour' => 35_000,
            'ot_included_hours_per_month' => 0,
            'ot_notes' => 'All OT billable to customer.',
            'customer_support_obligations' => 'Customer provides test environment + sample data.',
            'out_of_scope_policy' => 'Hardware procurement out of scope.',
            'working_hours' => '09:00 – 18:00 Mon–Fri JST',
            'testing_range' => 'Browser: Chrome + Edge latest.',
            'target_margin' => 50,
            'base_labor_cost' => $costs['base_labor'],
            'overhead_cost' => $costs['overhead'],
            'buffer_cost' => 0,
            'total_estimated_cost' => $costs['total_cost'],
            'estimated_gross_profit' => $costs['profit'],
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
            ['role_type' => 'backend', 'quantity' => 1, 'months' => $months],
            ['role_type' => 'qa',      'quantity' => 1, 'months' => $months],
        ], [], $admin);
    }

    /**
     * B-Project1: Arsenal Fc project — Oct–Dec 2026.
     * Qualified stage: deal + estimation only.
     */
    private function createArsenalFc(Tenant $tenant, array $employees, array $roles, User $admin, Carbon $yearStart, array $rankAvg): void
    {
        $team = [
            ['emp' => $employees['leader3'], 'role_code' => 'pm',       'feature' => 'Tech lead',         'hours_per_month' => 160, 'months' => 3],
            ['emp' => $employees['member8'], 'role_code' => 'backend',  'feature' => 'Backend services',   'hours_per_month' => 160, 'months' => 3],
            ['emp' => $employees['member9'], 'role_code' => 'frontend', 'feature' => 'Frontend + mobile',  'hours_per_month' => 160, 'months' => 3],
        ];
        $months = 3;
        $start = Carbon::create(2026, 10, 1);

        // 1L+2M for 3 months
        $monthlyTeam = array_fill(0, $months, ['leaders' => 1, 'members' => 2]);
        $costs = $this->projectCosts($monthlyTeam, $rankAvg);

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'Arsenal Fc project',
            'client' => 'Arsenal Football Club',
            'contact_name' => 'Ms. Wenger',
            'contact_email' => 'wenger@arsenal.example.com',
            'contact_phone' => '+44 20 0000 0000',
            'estimated_value' => $costs['total_income'],
            'win_probability' => 50,
            'status' => 'qualified',
            'lifecycle_status' => 'active',
            'expected_close_date' => $start->copy()->subDay()->toDateString(),
            'lead_source' => 'referral',
            'client_budget' => $costs['total_income'],
            'timeline_months' => $months,
            'workload_hours' => $months * 3 * 160,
            'workload_description' => 'Training analytics dashboard and scouting database. Project window: 2026/10 – 2026/12.',
            'ot_policy_model' => 'customer_pays_per_hour',
            'ot_rate_per_hour' => 35_000,
            'ot_included_hours_per_month' => 0,
            'ot_notes' => 'All OT billable.',
            'working_hours' => '09:00 – 18:00 Mon–Fri JST',
            'target_margin' => 50,
            'base_labor_cost' => $costs['base_labor'],
            'overhead_cost' => $costs['overhead'],
            'buffer_cost' => 0,
            'total_estimated_cost' => $costs['total_cost'],
            'estimated_gross_profit' => $costs['profit'],
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $team, $roles, [
            ['role_type' => 'pm',       'quantity' => 1, 'months' => $months],
            ['role_type' => 'backend',  'quantity' => 1, 'months' => $months],
            ['role_type' => 'frontend', 'quantity' => 1, 'months' => $months],
        ], [], $admin);
    }

    // ── Schedule history (on-track pattern) ───────────────────────────

    /**
     * Seed task → phase → progress log hierarchy for a set of team members.
     * All progress is on-track: used_hours = progress_hours, zero late_hours.
     */
    private function seedScheduleHistory(Tenant $tenant, Project $project, array $team, Carbon $start, int $months): void
    {
        $today = Carbon::now()->startOfDay();
        $totalWorkdays = max(1, (int) round($months * 20));

        $phaseTemplate = [
            ['code' => 'basic_doc',   'name' => '設計 (Design)',         'pct' => 0.10],
            ['code' => 'development', 'name' => '実装',                  'pct' => 0.70],
            ['code' => 'system_test', 'name' => 'テスト (Testing)',      'pct' => 0.20],
        ];

        $existingTaskCount = ProjectTaskAssignment::where('project_id', $project->id)->count();

        foreach ($team as $idx => $member) {
            $employee = $member['emp'];
            $memberMonths = $member['months'] ?? $months;
            $allocated = $member['hours_per_month'] * $memberMonths;

            $task = ProjectTaskAssignment::create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'row_no' => $existingTaskCount + $idx + 1,
                'function_id' => 'F'.str_pad((string) ($existingTaskCount + $idx + 1), 3, '0', STR_PAD_LEFT),
                'function_name' => $member['feature'],
                'category' => 'Implementation',
                'offshore' => false,
                'difficulty' => '普通',
                'total_hours' => $allocated,
            ]);

            $memberWorkdays = max(1, (int) round($memberMonths * 20));
            $cursor = $start->copy();

            foreach ($phaseTemplate as $order => $phase) {
                $phaseHours = round($allocated * $phase['pct'], 1);
                $phaseWorkdays = max(1, (int) round($memberWorkdays * $phase['pct']));
                $phaseCalendarDays = (int) ceil($phaseWorkdays * 7 / 5);
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
                    'assignment_source' => 'manual',
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

                $plannedWeeks = max(1, (int) ceil($phaseStart->diffInDays($phaseEnd) / 7));
                $weeklyPlanned = round($phaseHours / $plannedWeeks, 2);

                $endLog = $actualEnd ?? $today;
                $loggedHours = 0;
                $logCursor = $phaseStart->copy();

                while ($logCursor->lte($endLog) && $loggedHours < $phaseHours) {
                    $logDate = $logCursor->copy()->addDays(4); // Friday
                    if ($logDate->gt($endLog)) {
                        $logDate = $endLog->copy();
                    }
                    $hours = min($weeklyPlanned, round($phaseHours - $loggedHours, 2));
                    if ($hours <= 0) {
                        break;
                    }

                    PhaseProgressLog::create([
                        'tenant_id' => $tenant->id,
                        'phase_assignment_id' => $phaseAssignment->id,
                        'employee_id' => $employee->id,
                        'log_date' => $logDate->toDateString(),
                        'progress_hours' => $hours,
                        'used_hours' => $hours,
                        'late_hours' => 0,
                        'note' => 'Weekly progress',
                    ]);
                    $loggedHours += $hours;
                    $logCursor->addDays(7);
                }

                $cursor = $phaseEnd->copy()->addDay();
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function createWonDeal(Tenant $tenant, array $opts): Deal
    {
        $team = $opts['team'];
        $costs = $opts['costs'];

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => $opts['name'],
            'client' => $opts['client'],
            'contact_name' => 'Demo Contact',
            'contact_email' => 'contact@'.\Illuminate\Support\Str::slug($opts['client']).'.example.com',
            'contact_phone' => '+81 90 0000 0000',
            'estimated_value' => $costs['total_income'],
            'win_probability' => 100,
            'status' => 'won',
            'lifecycle_status' => 'active',
            'expected_close_date' => $opts['start']->copy()->subDays(7)->toDateString(),
            'lead_source' => 'partner',
            'client_budget' => $costs['total_income'],
            'timeline_months' => $opts['months'],
            'workload_hours' => $opts['workload_hours'],
            'workload_description' => $opts['name'].' — won-deal demo (V2 seeder).',
            'ot_policy_model' => $opts['ot_policy'],
            'ot_rate_per_hour' => $opts['ot_rate'],
            'ot_included_hours_per_month' => 0,
            'ot_notes' => $opts['ot_notes'],
            'customer_support_obligations' => 'Customer provides test env + sample data.',
            'out_of_scope_policy' => 'Hardware procurement out of scope.',
            'working_hours' => '09:00 – 18:00 Mon–Fri JST',
            'testing_range' => 'Browser: Chrome + Edge latest.',
            'target_margin' => 50,
            'base_labor_cost' => $costs['base_labor'],
            'overhead_cost' => $costs['overhead'],
            'buffer_cost' => 0,
            'total_estimated_cost' => $costs['total_cost'],
            'estimated_gross_profit' => $costs['profit'],
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
            'notes' => 'V2 demo contract.',
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
            $memberMonths = $member['months'] ?? $months;
            ProjectTeamAssignment::create([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'employee_id' => $member['emp']->id,
                'allocated_hours' => $member['hours_per_month'] * $memberMonths,
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
                'paid_amount' => $row['status'] === 'Paid' ? $row['amount'] : 0,
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
     * Create monthly time entries for each team member for elapsed months.
     * Each member gets one Approved entry per elapsed month at 160h.
     */
    private function createMonthlyTimeEntriesForTeam(Tenant $tenant, Project $project, User $admin, array $team, Carbon $start, int $projectMonths): void
    {
        $today = Carbon::now()->startOfDay();
        $totalApproved = 0;

        foreach ($team as $member) {
            $memberMonths = $member['months'] ?? $projectMonths;
            $memberStart = $start->copy();

            // For Wayne Enterprise extra member (5 months starting Feb)
            if ($memberMonths < $projectMonths) {
                $offset = $projectMonths - $memberMonths;
                // member3 starts from Feb if project starts Jan and they have 5 months
                // Find the offset: total 8 months, member has 5, starts at month index 1 (Feb)
                $memberStart = $start->copy()->addMonths(1); // Feb for the 5-month member
            }

            for ($m = 0; $m < $memberMonths; $m++) {
                $monthDate = $memberStart->copy()->addMonths($m);
                $logDate = $monthDate->copy()->endOfMonth();

                if ($logDate->gt($today)) {
                    if ($monthDate->month === $today->month && $monthDate->year === $today->year) {
                        $logDate = $today->copy();
                        $dayOfMonth = $today->day;
                        $daysInMonth = $today->daysInMonth;
                        $hours = round(160 * ($dayOfMonth / $daysInMonth), 1);
                    } else {
                        break;
                    }
                } else {
                    $hours = 160;
                }

                if ($hours <= 0) {
                    continue;
                }

                TimeEntry::create([
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'employee_id' => $member['emp']->id,
                    'approved_by' => $admin->id,
                    'task' => $monthDate->format('Y/m').' — '.$member['feature'],
                    'date' => $logDate->toDateString(),
                    'hours' => $hours,
                    'billable' => true,
                    'status' => 'Approved',
                    'approved_at' => $logDate->copy()->addDay(),
                ]);
                $totalApproved += $hours;
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

    /**
     * Compute rank-average monthly costPrices from all employees.
     * Matches the spreadsheet formula:
     *   Leader avg = SUM(leader costPrices) / leader_count
     *   Member avg = SUM(IT-member costPrices) / MEMBER_COST_DIVISOR
     * where costPrice = monthly_salary × (1 + overhead%).
     */
    private function computeRankAverages(array $employees): array
    {
        $overhead = 1 + self::OVERHEAD_PCT / 100;

        $leaders = collect($employees)->filter(fn ($e) => str_contains($e->role_name ?? '', 'Leader'));
        $members = collect($employees)->filter(fn ($e) => ($e->role_name ?? '') === 'IT Member');

        return [
            'leader' => $leaders->avg(fn ($e) => $e->monthly_salary * $overhead),
            'member' => $members->sum(fn ($e) => $e->monthly_salary * $overhead) / self::MEMBER_COST_DIVISOR,
        ];
    }

    /**
     * Compute project financials from a monthly team composition array.
     * Each entry: ['leaders' => N, 'members' => M].
     * Returns the full cost breakdown for the deal fields.
     */
    private function projectCosts(array $monthlyTeam, array $rankAvg): array
    {
        $totalCost = 0;
        foreach ($monthlyTeam as $month) {
            $totalCost += ($month['leaders'] * $rankAvg['leader'])
                        + ($month['members'] * $rankAvg['member']);
        }
        $totalIncome = $totalCost * self::SELL_MULTIPLIER;
        $baseLaborCost = $totalCost / (1 + self::OVERHEAD_PCT / 100);
        $overhead = $totalCost - $baseLaborCost;

        return [
            'total_cost'   => round($totalCost, 2),
            'total_income'  => round($totalIncome, 2),
            'base_labor'    => round($baseLaborCost, 2),
            'overhead'      => round($overhead, 2),
            'profit'        => round($totalIncome - $totalCost, 2),
            'monthly_costs' => array_map(fn ($m) => round(
                ($m['leaders'] * $rankAvg['leader']) + ($m['members'] * $rankAvg['member']),
                2
            ), $monthlyTeam),
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
            $memberMonths = $member['months'] ?? $deal->timeline_months;
            DealHardAssignment::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'employee_id' => $member['emp']->id,
                'allocated_hours' => $member['hours_per_month'] * $memberMonths,
            ]);
        }

        foreach ($team as $member) {
            $memberMonths = $member['months'] ?? $deal->timeline_months;
            EstimationResource::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'job_role_id' => $member['emp']->job_role_id,
                'role_id' => $member['emp']->job_role_id,
                'feature_name' => $member['feature'],
                'hours' => $member['hours_per_month'] * $memberMonths,
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
                'hours' => $m['hours_per_month'] * ($m['months'] ?? $deal->timeline_months),
                'employeeId' => $m['emp']->id,
            ], $team),
            'overheads' => array_map(fn ($o) => [
                'name' => $o['name'],
                'cost' => $o['cost'],
            ], $overheads),
            'target_margin' => $deal->target_margin,
            'notes' => 'Seeded estimate — V2 demo.',
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
