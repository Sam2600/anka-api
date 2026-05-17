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
use App\Models\InitialBudget;
use App\Models\EstimationResource;
use App\Models\EstimationVersion;
use App\Models\Invoice;
use App\Models\Milestone;
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
 * Seeds "Yangon Works" — a second Yangon-based IT services agency
 * tenant for demo/judging. Mirrors DatabaseSeeder's per-tenant flow
 * (capacity roles → ranks → departments → roles → skills → employees
 * → users → settings → deals) but stands alone: only Yangon Works is
 * touched, every other tenant's data is left alone.
 *
 * Idempotent — running again wipes only Yangon Works' rows and
 * recreates them. Run via:
 *
 *   php artisan db:seed --class=YangonWorksSeeder
 */
class YangonWorksSeeder extends Seeder
{
    private const SLUG = 'yangon-works';
    private const PASSWORD = 'Demo@1234';

    public function run(): void
    {
        Model::unguarded(function () {
            $this->wipeExisting();

            $tenant = Tenant::create([
                'name' => 'Yangon Works',
                'slug' => self::SLUG,
                'plan' => 'pro',
                'currency' => 'MMK',
                'is_active' => true,
                'signatory_name' => 'U Thant Zin',
                'signatory_title' => 'Managing Director',
            ]);
            app()->instance('tenant_id', $tenant->id);

            $capacityRoles = $this->createCapacityRoles($tenant);
            $ranks = $this->createRanks($tenant);
            $departments = $this->createDepartments($tenant);
            $roles = $this->createRoles($tenant, $departments);
            $skills = $this->createSkills($tenant);
            $employees = $this->createEmployees($tenant, $departments, $roles, $capacityRoles, $ranks, $skills);
            $users = $this->createUsers($tenant, $employees);
            $this->finishDepartments($departments, $employees);
            $this->createCompanySettings($tenant);
            $this->createDeals($tenant, $employees, $roles, $users);

            $this->command->info("Yangon Works tenant seeded.");
            $this->command->info("  Tenant ID: {$tenant->id}");
            $this->command->info("  Users:");
            foreach ($users as $user) {
                $this->command->info("    {$user->email} / " . self::PASSWORD);
            }
        });
    }

    /**
     * Remove any prior Yangon Works rows in FK-safe order. Doesn't touch
     * other tenants. Uses tenant_id filtering rather than relying on
     * cascading FK deletes (most FKs in this schema are RESTRICT).
     */
    private function wipeExisting(): void
    {
        $tenant = Tenant::where('slug', self::SLUG)->first();
        if (! $tenant) {
            return;
        }

        $tenantId = $tenant->id;

        foreach ([
            'ai_usage_logs',
            'audit_logs',
            'personal_access_tokens',
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
            'frontend' => 'Frontend Engineer',
            'backend' => 'Backend Engineer',
            'design' => 'Product Designer',
            'pm' => 'Project Manager',
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

    /**
     * 7 ranks total — the 4 base (Junior/Mid/Senior/Lead) plus the 3
     * senior ranks (Manager/Director/Executive) that migration
     * 2026_05_16_000005 backfills onto existing tenants. New tenants
     * created after that migration must seed the senior ranks
     * themselves, otherwise the Authorized Signatory dropdown is empty
     * for this tenant.
     */
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
        $departments = [];
        foreach (['Sales', 'Delivery', 'Operations', 'Product Design'] as $name) {
            $departments[$name] = Department::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'headcount' => 0,
            ]);
        }
        return $departments;
    }

    /**
     * Role rates are billable hourly rates in MMK — used by Estimation
     * when an employee can't be matched directly (falls back to
     * role.rate × costToBillRatio). Numbers are conservative for Yangon
     * IT-services market rates.
     */
    private function createRoles(Tenant $tenant, array $departments): array
    {
        $rows = [
            ['title' => 'Sales Director',     'department' => 'Sales',          'rate' => 75000],
            ['title' => 'Account Manager',    'department' => 'Sales',          'rate' => 45000],
            ['title' => 'Tech Lead',          'department' => 'Delivery',       'rate' => 80000],
            ['title' => 'Senior Backend Engineer', 'department' => 'Delivery',  'rate' => 65000],
            ['title' => 'Backend Engineer',   'department' => 'Delivery',       'rate' => 45000],
            ['title' => 'Frontend Engineer',  'department' => 'Delivery',       'rate' => 45000],
            ['title' => 'QA Engineer',        'department' => 'Delivery',       'rate' => 35000],
            ['title' => 'Project Manager',    'department' => 'Operations',     'rate' => 60000],
            ['title' => 'Product Designer',   'department' => 'Product Design', 'rate' => 50000],
        ];

        $roles = [];
        foreach ($rows as $row) {
            $dept = $departments[$row['department']];
            $roles[$row['title']] = Role::create([
                'tenant_id' => $tenant->id,
                'department_id' => $dept->id,
                'title' => $row['title'],
                'department' => $dept->name,
                'rate' => $row['rate'],
            ]);
        }
        return $roles;
    }

    private function createSkills(Tenant $tenant): array
    {
        $rows = [
            'Laravel'             => 'Technical',
            'React'               => 'Technical',
            'PostgreSQL'          => 'Technical',
            'AWS'                 => 'Technical',
            'QA Automation'       => 'Technical',
            'UI/UX Design'        => 'Creative',
            'Project Management'  => 'Management',
            'Client Relations'    => 'Management',
        ];

        $skills = [];
        foreach ($rows as $name => $category) {
            $skills[$name] = Skill::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'category' => $category,
            ]);
        }
        return $skills;
    }

    /**
     * 9 employees. Job titles are chosen so deriveRankCode (the
     * heuristic used by DatabaseSeeder + the AI prompts) maps to the
     * right rank:
     *   - "Director" / "Lead" / "Manager" → Lead rank
     *   - "Senior X"                       → Senior rank
     *   - bare title                       → Mid rank
     * For senior-rank employees (Manager/Director), we override
     * rank_id explicitly to use the senior ranks so the Authorized
     * Signatory dropdown shows the actual seniority.
     */
    private function createEmployees(
        Tenant $tenant,
        array $departments,
        array $roles,
        array $capacityRoles,
        array $ranks,
        array $skills,
    ): array {
        $rows = [
            // Signatory + admin user — promoted to Director rank.
            [
                'key' => 'md',
                'name' => 'U Thant Zin',
                'role' => 'Sales Director',
                'department' => 'Sales',
                'capacity' => 'pm',
                'salary' => 6_500_000,
                'hours' => 168,
                'rank' => 'Director',
                'skills' => ['Client Relations' => 'expert', 'Project Management' => 'expert'],
            ],
            // Sales user.
            [
                'key' => 'account_manager',
                'name' => 'Daw Mya Mya',
                'role' => 'Account Manager',
                'department' => 'Sales',
                'capacity' => 'pm',
                'salary' => 3_500_000,
                'hours' => 168,
                'rank' => 'Mid',
                'skills' => ['Client Relations' => 'intermediate'],
            ],
            // Delivery user — promoted to Manager rank.
            [
                'key' => 'tech_lead',
                'name' => 'Ko Aung Aung',
                'role' => 'Tech Lead',
                'department' => 'Delivery',
                'capacity' => 'backend',
                'salary' => 5_500_000,
                'hours' => 168,
                'rank' => 'Manager',
                'skills' => ['Laravel' => 'expert', 'PostgreSQL' => 'expert', 'AWS' => 'expert'],
            ],
            [
                'key' => 'senior_backend',
                'name' => 'Ma Khaing Su',
                'role' => 'Senior Backend Engineer',
                'department' => 'Delivery',
                'capacity' => 'backend',
                'salary' => 4_200_000,
                'hours' => 168,
                'rank' => 'Senior',
                'skills' => ['Laravel' => 'expert', 'PostgreSQL' => 'intermediate'],
            ],
            [
                'key' => 'backend',
                'name' => 'Ko Min Thu',
                'role' => 'Backend Engineer',
                'department' => 'Delivery',
                'capacity' => 'backend',
                'salary' => 2_800_000,
                'hours' => 168,
                'rank' => 'Mid',
                'skills' => ['Laravel' => 'intermediate', 'PostgreSQL' => 'intermediate'],
            ],
            [
                'key' => 'frontend',
                'name' => 'Ko Zaw Lin',
                'role' => 'Frontend Engineer',
                'department' => 'Delivery',
                'capacity' => 'frontend',
                'salary' => 2_800_000,
                'hours' => 168,
                'rank' => 'Mid',
                'skills' => ['React' => 'expert'],
            ],
            [
                'key' => 'qa',
                'name' => 'Ma Su Su',
                'role' => 'QA Engineer',
                'department' => 'Delivery',
                'capacity' => 'qa',
                'salary' => 2_200_000,
                'hours' => 168,
                'rank' => 'Junior',
                'skills' => ['QA Automation' => 'intermediate'],
            ],
            [
                'key' => 'pm',
                'name' => 'U Phyo Wai',
                'role' => 'Project Manager',
                'department' => 'Operations',
                'capacity' => 'pm',
                'salary' => 4_500_000,
                'hours' => 168,
                'rank' => 'Senior',
                'skills' => ['Project Management' => 'expert', 'Client Relations' => 'expert'],
            ],
            [
                'key' => 'designer',
                'name' => 'Daw Hla Hla',
                'role' => 'Product Designer',
                'department' => 'Product Design',
                'capacity' => 'design',
                'salary' => 3_200_000,
                'hours' => 168,
                'rank' => 'Mid',
                'skills' => ['UI/UX Design' => 'expert'],
            ],
        ];

        $employees = [];
        foreach ($rows as $row) {
            $role = $roles[$row['role']];
            $employee = Employee::create([
                'tenant_id' => $tenant->id,
                'department_id' => $departments[$row['department']]->id,
                'job_role_id' => $role->id,
                'name' => $row['name'],
                'role' => $row['role'],
                'role_name' => $row['role'],
                'capacity_role' => $row['capacity'],
                'capacity_role_id' => $capacityRoles[$row['capacity']]->id,
                'rank_id' => $ranks[$row['rank']]->id,
                // Spec ①.2 — Basic + Allowance. Seed treats $row['salary'] as
                // basic with no allowance; the model save hook derives
                // monthly_salary = basic + allowance for legacy readers.
                'basic_salary' => $row['salary'],
                'allowance' => 0,
                'workable_hours' => $row['hours'],
                'status' => 'Active',
            ]);

            foreach ($row['skills'] as $skillName => $proficiency) {
                EmployeeSkill::create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'skill_id' => $skills[$skillName]->id,
                    'proficiency' => $proficiency,
                ]);
            }

            $employees[$row['key']] = $employee->fresh();
        }

        return $employees;
    }

    private function createUsers(Tenant $tenant, array $employees): array
    {
        $rows = [
            [
                'key' => 'admin',
                'employee' => 'md',
                'first_name' => 'Thant',
                'last_name' => 'Zin',
                'email' => 'admin@yangonworks.com.mm',
                'role' => 'Admin',
            ],
            [
                'key' => 'sales',
                'employee' => 'account_manager',
                'first_name' => 'Mya',
                'last_name' => 'Mya',
                'email' => 'sales@yangonworks.com.mm',
                'role' => 'Sales',
            ],
            [
                'key' => 'delivery',
                'employee' => 'tech_lead',
                'first_name' => 'Aung',
                'last_name' => 'Aung',
                'email' => 'delivery@yangonworks.com.mm',
                'role' => 'Delivery',
            ],
        ];

        $users = [];
        foreach ($rows as $row) {
            $employee = $employees[$row['employee']];
            $users[$row['key']] = User::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'password' => Hash::make(self::PASSWORD),
                'system_role' => 'member',
                'app_role' => $row['role'],
                'is_super_admin' => false,
            ]);
        }
        return $users;
    }

    private function finishDepartments(array $departments, array $employees): void
    {
        $managers = [
            'Sales' => 'md',
            'Delivery' => 'tech_lead',
            'Operations' => 'pm',
            'Product Design' => 'designer',
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
            'overhead_percentage' => 18,
            'buffer_percentage' => 10,
            'yearly_fixed_cost' => 240_000_000,
            // Legacy column kept during the soft cutover to year-scoped budgets
            // (initial_budgets table). New code reads from InitialBudget below;
            // this stays for backward-compat until phase 2 drops the column.
            'annual_initial_budget' => 1_500_000_000,
            'employer_tax_percentage' => 5,
            'benefits_percentage' => 8,
            'cost_to_bill_ratio' => 0.40,
            'default_monthly_capacity_hours' => 160,
            'fallback_hourly_cost' => 4000,
        ]);

        // Year-scoped budget (process ①.3). Forecast reads this for the
        // current fiscal year's profit target.
        InitialBudget::create([
            'tenant_id' => $tenant->id,
            'fiscal_year' => (int) date('Y'),
            'amount' => 1_500_000_000,
        ]);
    }

    // ── Deal seeding ───────────────────────────────────────────────────

    /**
     * Four deals at each rank — C lead, B qualified, A negotiation, S won.
     * Costs / margins are computed from the actual hard assignments so
     * the Financial Summary card renders sensible numbers without
     * requiring an Estimation page round-trip.
     */
    private function createDeals(Tenant $tenant, array $employees, array $roles, array $users): void
    {
        $this->createLeadDeal($tenant, $employees, $roles, $users['admin']);
        $this->createQualifiedDeal($tenant, $employees, $roles, $users['admin']);
        $this->createNegotiationDeal($tenant, $employees, $roles, $users['admin']);
        $this->createWonDeal($tenant, $employees, $roles, $users['admin']);
    }

    /**
     * Rank C — bare-minimum lead, no estimation yet. Sets the contact
     * + brief workload only, so the Customer Requirements card shows
     * "1/8 captured" and the Financial Summary card stays hidden
     * (which is what the deal-detail page now does for C leads).
     */
    private function createLeadDeal(Tenant $tenant, array $employees, array $roles, User $admin): Deal
    {
        return Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'Yangon General Hospital — Patient Portal',
            'client' => 'Yangon General Hospital',
            'contact_name' => 'Daw Khin Mar',
            'contact_email' => 'khin.mar@ygh.example.mm',
            'contact_phone' => '+95 9 7777 0101',
            'estimated_value' => 80_000_000,
            'win_probability' => 30,
            'status' => 'lead',
            'lifecycle_status' => 'active',
            'expected_close_date' => Carbon::now()->addDays(30)->toDateString(),
            'lead_source' => 'referral',
            'client_budget' => 80_000_000,
            'timeline_months' => 6,
            'workload_hours' => 1200,
            'workload_description' => 'Public-facing patient portal for appointment booking, lab result viewing, and prescription refill requests. Integrates with the hospital\'s existing HIS via REST.',
            'wizard_step' => 'context',
        ]);
    }

    /**
     * Rank B — qualified, has ghost roles + estimation rows but no
     * final_* lock-in yet. ot_policy is also captured at this stage
     * so the contract drafting wizard has something to render.
     */
    private function createQualifiedDeal(Tenant $tenant, array $employees, array $roles, User $admin): Deal
    {
        $assignments = [
            ['employee' => $employees['tech_lead'],       'hours' => 240],
            ['employee' => $employees['senior_backend'],  'hours' => 480],
            ['employee' => $employees['frontend'],        'hours' => 480],
            ['employee' => $employees['qa'],              'hours' => 240],
        ];
        $clientBudget = 120_000_000;
        $rollup = $this->rollupCosts($assignments, $clientBudget, overheadPct: 0.18, bufferPct: 0.10);

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'Mandalay Cement Group — Operations Platform',
            'client' => 'Mandalay Cement Group',
            'contact_name' => 'U Maung Maung',
            'contact_email' => 'maung.maung@mcg.example.mm',
            'contact_phone' => '+95 9 7777 0202',
            'estimated_value' => $clientBudget,
            'win_probability' => 50,
            'status' => 'qualified',
            'lifecycle_status' => 'active',
            'expected_close_date' => Carbon::now()->addDays(45)->toDateString(),
            'lead_source' => 'cold_outreach',
            'client_budget' => $clientBudget,
            'timeline_months' => 8,
            'workload_hours' => 1600,
            'workload_description' => 'Internal operations platform for cement production tracking: kiln throughput dashboards, raw material inventory, dispatch scheduling, and driver mobile app.',
            'ot_policy_model' => 'capped_then_customer_pays',
            'ot_rate_per_hour' => 35000,
            'ot_included_hours_per_month' => 10,
            'ot_notes' => 'First 10 OT hours per month absorbed; beyond that, billed at the OT rate.',
            'working_hours' => '08:30 AM – 05:30 PM (Mon–Fri MST)',
            'target_margin' => 30,
            'base_labor_cost' => $rollup['labor'],
            'overhead_cost' => $rollup['overhead'],
            'buffer_cost' => $rollup['buffer'],
            'total_estimated_cost' => $rollup['total'],
            'estimated_gross_profit' => $rollup['profit'],
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $assignments, $employees, $roles, [
            ['role_type' => 'backend',  'quantity' => 2, 'months' => 80],
            ['role_type' => 'frontend', 'quantity' => 1, 'months' => 100],
            ['role_type' => 'qa',       'quantity' => 1, 'months' => 50],
        ], [
            ['role' => 'Tech Lead',                'role_code' => 'pm',       'feature' => 'Architecture + delivery oversight', 'hours' => 240],
            ['role' => 'Senior Backend Engineer',  'role_code' => 'backend',  'feature' => 'Production tracking + inventory API', 'hours' => 480],
            ['role' => 'Frontend Engineer',        'role_code' => 'frontend', 'feature' => 'Operator dashboards', 'hours' => 360],
            ['role' => 'Backend Engineer',         'role_code' => 'backend',  'feature' => 'Driver mobile app backend', 'hours' => 280],
            ['role' => 'QA Engineer',              'role_code' => 'qa',       'feature' => 'Automated + smoke testing', 'hours' => 240],
        ], [
            ['name' => 'Cloud infra (dev + staging)', 'cost' => 2_400_000],
            ['name' => 'Project management tooling',  'cost' => 600_000],
        ], $admin);

        return $deal;
    }

    /**
     * Rank A — negotiation, Estimation handoff complete. All
     * REQUIRED_ESTIMATION_FIELDS are set (final_monthly_fee,
     * final_contract_months, final_team_summary, final_currency,
     * final_confirmed_at) plus full customer requirements. This deal
     * is contract-drafting-ready: the [Generate Contract Draft]
     * button on the detail page will be enabled.
     */
    private function createNegotiationDeal(Tenant $tenant, array $employees, array $roles, User $admin): Deal
    {
        $assignments = [
            ['employee' => $employees['tech_lead'],       'hours' => 360],
            ['employee' => $employees['senior_backend'],  'hours' => 720],
            ['employee' => $employees['backend'],         'hours' => 480],
            ['employee' => $employees['pm'],              'hours' => 240],
        ];
        $clientBudget = 180_000_000;
        $rollup = $this->rollupCosts($assignments, $clientBudget, overheadPct: 0.18, bufferPct: 0.10);

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inya Lake Hotel — Off-site Cloud Backup',
            'client' => 'Inya Lake Hotel & Resort',
            'contact_name' => 'Mr. Toshi Watanabe',
            'contact_email' => 'toshi.watanabe@inya-lake.example.mm',
            'contact_phone' => '+95 9 7777 0303',
            'estimated_value' => $clientBudget,
            'win_probability' => 80,
            'status' => 'negotiation',
            'lifecycle_status' => 'active',
            'expected_close_date' => Carbon::now()->addDays(20)->toDateString(),
            'lead_source' => 'inbound',
            'client_budget' => $clientBudget,
            'timeline_months' => 12,
            'workload_hours' => 1800,
            'workload_description' => 'Off-site cloud backup service for the hotel\'s PMS and POS systems hosted on three on-prem Windows Server 2019 nodes. Total source data ~4 TB, growing at ~120 GB/month. Veritas Backup Exec 22.x; nightly incremental + weekly full to AWS S3 + Glacier.',
            'ot_policy_model' => 'capped_then_customer_pays',
            'ot_rate_per_hour' => 35000,
            'ot_included_hours_per_month' => 8,
            'ot_notes' => 'First 8 OT hours per month absorbed; beyond that billed at the OT rate.',
            'customer_support_obligations' => 'Provider responds to severity-1 incidents within 2 hours during business window; severity-2 within 1 business day.',
            'out_of_scope_policy' => 'Hardware replacement on customer premises and primary-side database tuning are out of scope.',
            'working_hours' => '09:00 AM – 04:00 PM (Mon–Fri MST)',
            'testing_range' => 'Quarterly restore validation: one randomly-selected weekly full backup restored to a staging environment and verified for boot + data integrity.',
            'target_margin' => 32,
            'base_labor_cost' => $rollup['labor'],
            'overhead_cost' => $rollup['overhead'],
            'buffer_cost' => $rollup['buffer'],
            'total_estimated_cost' => $rollup['total'],
            'estimated_gross_profit' => $rollup['profit'],
            'final_monthly_fee' => 15_000_000,
            'final_installation_fee' => 8_000_000,
            'final_contract_months' => 12,
            'final_ot_policy' => 'Capped — first 8 OT hours per month included; beyond that billed at MMK 35,000/hr.',
            'final_support_hours_per_month' => 40,
            'final_team_summary' => '1 Tech Lead (Ko Aung Aung) + 1 Senior Backend Engineer (Ma Khaing Su) + 1 Backend Engineer (Ko Min Thu) + 1 Project Manager (U Phyo Wai).',
            'final_currency' => 'MMK',
            'final_confirmed_at' => Carbon::now()->subDays(3),
            'suggested_template_variant' => 'cloud_backup',
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $assignments, $employees, $roles, [
            ['role_type' => 'backend', 'quantity' => 2, 'months' => 100],
            ['role_type' => 'pm',      'quantity' => 1, 'months' => 50],
        ], [
            ['role' => 'Tech Lead',                'role_code' => 'pm',      'feature' => 'Backup architecture + customer liaison', 'hours' => 360],
            ['role' => 'Senior Backend Engineer',  'role_code' => 'backend', 'feature' => 'AWS S3/Glacier integration + restore tooling', 'hours' => 720],
            ['role' => 'Backend Engineer',         'role_code' => 'backend', 'feature' => 'Backup monitoring + alerting', 'hours' => 480],
            ['role' => 'Project Manager',          'role_code' => 'pm',      'feature' => 'Coordination + quarterly restore tests', 'hours' => 240],
        ], [
            ['name' => 'AWS storage cost reserve', 'cost' => 4_800_000],
            ['name' => 'On-call rotation tooling', 'cost' => 1_200_000],
        ], $admin);

        return $deal;
    }

    /**
     * Rank S — won deal with full delivery records: Contract +
     * Project + 3 milestones (one paid invoice) + project team
     * assignments + a few time entries to give the Project Detail
     * + Time Tracking pages something to render. Equivalent to
     * what the win_deal() Postgres stored procedure would have
     * produced + a few months of operating data layered on top.
     */
    private function createWonDeal(Tenant $tenant, array $employees, array $roles, User $admin): Deal
    {
        $assignments = [
            ['employee' => $employees['tech_lead'],       'hours' => 480],
            ['employee' => $employees['senior_backend'],  'hours' => 800],
            ['employee' => $employees['backend'],         'hours' => 640],
            ['employee' => $employees['pm'],              'hours' => 320],
        ];
        $clientBudget = 240_000_000;
        $rollup = $this->rollupCosts($assignments, $clientBudget, overheadPct: 0.18, bufferPct: 0.10);

        $startDate = Carbon::now()->subMonths(3)->startOfMonth();
        $endDate = (clone $startDate)->addMonths(12)->endOfMonth();

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => 'Yangon City Bank — DevOps Engineer Dispatch',
            'client' => 'Yangon City Bank',
            'contact_name' => 'U Sai Khun',
            'contact_email' => 'sai.khun@ycb.example.mm',
            'contact_phone' => '+95 9 7777 0404',
            'estimated_value' => $clientBudget,
            'win_probability' => 100,
            'status' => 'won',
            'lifecycle_status' => 'active',
            'expected_close_date' => $startDate->copy()->subDays(10)->toDateString(),
            'lead_source' => 'partner',
            'client_budget' => $clientBudget,
            'timeline_months' => 12,
            'workload_hours' => 2400,
            'workload_description' => 'Embedded DevOps engineer dispatch for the bank\'s digital banking platform: CI/CD pipeline maintenance, infra-as-code (Terraform on AWS), and on-call escalation support during deployment windows.',
            'ot_policy_model' => 'customer_pays_per_hour',
            'ot_rate_per_hour' => 45000,
            'ot_included_hours_per_month' => 0,
            'ot_notes' => 'All OT hours billable at MMK 45,000/hr with 24h notice.',
            'customer_support_obligations' => 'Provider engineer joins customer\'s daily standups and weekly platform reviews.',
            'out_of_scope_policy' => 'Core banking application code changes and database administration are out of scope.',
            'working_hours' => '09:00 AM – 06:00 PM (Mon–Fri MST), on-call rotation Sat–Sun.',
            'testing_range' => 'Pipeline changes go through customer staging environment + smoke test suite before production rollout.',
            'target_margin' => 35,
            'base_labor_cost' => $rollup['labor'],
            'overhead_cost' => $rollup['overhead'],
            'buffer_cost' => $rollup['buffer'],
            'total_estimated_cost' => $rollup['total'],
            'estimated_gross_profit' => $rollup['profit'],
            'final_monthly_fee' => 20_000_000,
            'final_installation_fee' => 0,
            'final_contract_months' => 12,
            'final_ot_policy' => 'All OT hours billable at MMK 45,000/hr; 24h notice required.',
            'final_support_hours_per_month' => 160,
            'final_team_summary' => '1 Tech Lead (Ko Aung Aung) + 1 Senior Backend Engineer (Ma Khaing Su) + 1 Backend Engineer (Ko Min Thu) + 1 Project Manager (U Phyo Wai).',
            'final_currency' => 'MMK',
            'final_confirmed_at' => $startDate->copy()->subDays(7),
            'suggested_template_variant' => 'engineer_dispatch',
            'won_at' => $startDate->copy()->subDays(2),
            'win_reason' => 'Strong reference from prior tenant and competitive monthly fee.',
            'wizard_step' => 'complete',
        ]);

        $this->seedDealChildren($tenant, $deal, $assignments, $employees, $roles, [
            ['role_type' => 'backend', 'quantity' => 2, 'months' => 100],
            ['role_type' => 'pm',      'quantity' => 1, 'months' => 60],
        ], [
            ['role' => 'Tech Lead',                'role_code' => 'pm',      'feature' => 'Platform architecture + customer-side review participation', 'hours' => 480],
            ['role' => 'Senior Backend Engineer',  'role_code' => 'backend', 'feature' => 'CI/CD pipeline + IaC implementation', 'hours' => 800],
            ['role' => 'Backend Engineer',         'role_code' => 'backend', 'feature' => 'Deployment automation + on-call support', 'hours' => 640],
            ['role' => 'Project Manager',          'role_code' => 'pm',      'feature' => 'Coordination + customer stakeholder management', 'hours' => 320],
        ], [
            ['name' => 'AWS infra provisioning',   'cost' => 6_000_000],
            ['name' => 'Terraform Cloud tier',     'cost' => 1_200_000],
        ], $admin);

        $this->createDelivery($tenant, $deal, $employees, $admin, $startDate, $endDate);

        return $deal;
    }

    /**
     * Contract + Project + Milestones + Invoice + TeamAssignments +
     * TimeEntries — the post-win delivery state. Equivalent to what
     * win_deal() builds in Postgres, layered with a few months of
     * billing + time-tracking history for demo purposes.
     */
    private function createDelivery(Tenant $tenant, Deal $deal, array $employees, User $admin, Carbon $startDate, Carbon $endDate): void
    {
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'deal_id' => $deal->id,
            'contract_number' => 'YWK-CON-2026-001',
            'client' => $deal->client,
            'total_value' => $deal->client_budget,
            'revenue_recognized' => 0,
            'status' => 'Active',
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'signed_at' => $startDate->copy()->subDay(),
            'payment_terms_days' => 7,
            'currency' => 'MMK',
            'notes' => 'Embedded DevOps engineer dispatch — monthly retainer.',
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'project_number' => 'YWK-PRJ-2026-101',
            'name' => $deal->name,
            'client' => $deal->client,
            'budget_hours' => $deal->workload_hours,
            'consumed_hours' => 0,
            'status' => 'On Track',
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'kickoff_date' => $startDate->toDateString(),
            'project_manager_id' => $employees['pm']->id,
        ]);

        $milestones = [
            ['key' => 'm1', 'name' => 'Month 1 — Onboarding & pipeline audit',  'offset_months' => 1, 'amount' => 20_000_000, 'status' => 'Completed', 'sequence' => 1],
            ['key' => 'm2', 'name' => 'Month 2 — IaC migration baseline',       'offset_months' => 2, 'amount' => 20_000_000, 'status' => 'Completed', 'sequence' => 2],
            ['key' => 'm3', 'name' => 'Month 3 — Deployment automation rollout','offset_months' => 3, 'amount' => 20_000_000, 'status' => 'In Progress', 'sequence' => 3],
            ['key' => 'm4', 'name' => 'Month 4 — Quarterly review',             'offset_months' => 4, 'amount' => 20_000_000, 'status' => 'Pending',     'sequence' => 4],
        ];

        $milestoneIds = [];
        foreach ($milestones as $row) {
            $dueDate = $startDate->copy()->addMonths($row['offset_months']);
            $milestone = Milestone::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'name' => $row['name'],
                'due_date' => $dueDate->toDateString(),
                'amount' => $row['amount'],
                'status' => $row['status'],
                'completed_at' => $row['status'] === 'Completed' ? $dueDate->copy()->addDay() : null,
                'sequence_number' => $row['sequence'],
            ]);
            $milestoneIds[$row['key']] = $milestone->id;
        }

        $invoices = [
            ['number' => 'YWK-INV-2026-001', 'milestone' => 'm1', 'amount' => 20_000_000, 'tax' => 1_000_000, 'status' => 'Paid',
             'issue_offset' => 1, 'due_offset' => 7, 'paid_offset' => 5,
             'notes' => 'Month 1 retainer paid.'],
            ['number' => 'YWK-INV-2026-002', 'milestone' => 'm2', 'amount' => 20_000_000, 'tax' => 1_000_000, 'status' => 'Paid',
             'issue_offset' => 1, 'due_offset' => 7, 'paid_offset' => 6,
             'notes' => 'Month 2 retainer paid.'],
            ['number' => 'YWK-INV-2026-003', 'milestone' => 'm3', 'amount' => 20_000_000, 'tax' => 1_000_000, 'status' => 'Pending',
             'issue_offset' => 1, 'due_offset' => 7, 'paid_offset' => null,
             'notes' => 'Month 3 retainer issued — awaiting customer payment.'],
        ];

        $recognized = 0;
        foreach ($invoices as $idx => $row) {
            $monthBase = $startDate->copy()->addMonths($idx + 1);
            $issueDate = $monthBase->copy()->addDays($row['issue_offset']);
            $dueDate = $issueDate->copy()->addDays($row['due_offset']);
            $paidAt = $row['paid_offset'] !== null ? $issueDate->copy()->addDays($row['paid_offset']) : null;

            Invoice::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'milestone_id' => $milestoneIds[$row['milestone']],
                'invoice_number' => $row['number'],
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'amount' => $row['amount'],
                'tax' => $row['tax'],
                'status' => $row['status'],
                'paid_at' => $paidAt,
                'notes' => $row['notes'],
            ]);

            if ($row['status'] === 'Paid') {
                $recognized += $row['amount'] + $row['tax'];
            }
        }

        $contract->update(['revenue_recognized' => $recognized]);

        foreach ($deal->hard_assignments as $assignment) {
            ProjectTeamAssignment::create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'employee_id' => $assignment->employee_id,
                'allocated_hours' => $assignment->allocated_hours,
                'assignment_source' => 'deal_transfer',
            ]);
        }

        // A handful of time entries — enough to nudge consumed_hours
        // off zero so the Project detail card shows real progress.
        $timeEntries = [
            ['employee' => 'senior_backend', 'task' => 'GitHub Actions CI pipeline audit',          'offset_days' => 5,  'hours' => 8.0,  'status' => 'Approved'],
            ['employee' => 'senior_backend', 'task' => 'Terraform module: VPC + EKS baseline',      'offset_days' => 12, 'hours' => 8.0,  'status' => 'Approved'],
            ['employee' => 'backend',        'task' => 'Deployment hook scripts',                   'offset_days' => 14, 'hours' => 7.5,  'status' => 'Approved'],
            ['employee' => 'tech_lead',      'task' => 'Stakeholder review + architecture walk',    'offset_days' => 20, 'hours' => 4.0,  'status' => 'Approved'],
            ['employee' => 'pm',             'task' => 'Sprint planning + retro',                   'offset_days' => 30, 'hours' => 3.0,  'status' => 'Approved'],
            ['employee' => 'backend',        'task' => 'On-call rotation — Saturday escalation',    'offset_days' => 45, 'hours' => 6.0,  'status' => 'Pending'],
        ];

        $approvedHours = 0;
        foreach ($timeEntries as $row) {
            $entryDate = $startDate->copy()->addDays($row['offset_days']);
            $entry = TimeEntry::create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'employee_id' => $employees[$row['employee']]->id,
                'approved_by' => $row['status'] === 'Approved' ? $admin->id : null,
                'task' => $row['task'],
                'date' => $entryDate->toDateString(),
                'hours' => $row['hours'],
                'billable' => true,
                'status' => $row['status'],
                'approved_at' => $row['status'] === 'Approved' ? $entryDate->copy()->addDay() : null,
            ]);

            if ($entry->status === 'Approved') {
                $approvedHours += $entry->hours;
            }
        }

        $project->update(['consumed_hours' => $approvedHours]);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Compute base_labor / overhead / buffer / total / gross_profit
     * from assignments + client_budget — same formula as
     * EstimationSimulator's costRateForResource → laborCost →
     * companyOverhead → buffer → total chain.
     */
    private function rollupCosts(array $assignments, float $clientBudget, float $overheadPct, float $bufferPct): array
    {
        $labor = collect($assignments)->sum(function (array $a) {
            $emp = $a['employee'];
            $hourly = $emp->workable_hours > 0 ? $emp->monthly_salary / $emp->workable_hours : 0;
            return $a['hours'] * $hourly;
        });
        $overhead = $labor * $overheadPct;
        $buffer = ($labor + $overhead) * $bufferPct;
        $total = $labor + $overhead + $buffer;
        return [
            'labor' => round($labor, 2),
            'overhead' => round($overhead, 2),
            'buffer' => round($buffer, 2),
            'total' => round($total, 2),
            'profit' => round($clientBudget - $total, 2),
        ];
    }

    /**
     * Write ghost roles, hard assignments, estimation resources, and
     * deal overheads, plus a baseline EstimationVersion snapshot.
     * Shared by every deal at B and above (C leads skip estimation).
     */
    private function seedDealChildren(
        Tenant $tenant,
        Deal $deal,
        array $assignments,
        array $employees,
        array $roles,
        array $ghostRoles,
        array $resources,
        array $overheads,
        User $admin,
    ): void {
        foreach ($ghostRoles as $row) {
            $range = $this->salaryRange($employees, $row['role_type']);
            DealGhostRole::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'role_type' => $row['role_type'],
                'quantity' => $row['quantity'],
                'months' => $row['months'] ?? 100,
                'avg_monthly_salary' => round(($range['min'] + $range['max']) / 2, 2),
                'min_monthly_salary' => $range['min'],
                'max_monthly_salary' => $range['max'],
            ]);
        }

        foreach ($assignments as $assignment) {
            DealHardAssignment::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'employee_id' => $assignment['employee']->id,
                'allocated_hours' => $assignment['hours'],
            ]);
        }

        foreach ($resources as $resource) {
            $role = $roles[$resource['role']] ?? null;
            EstimationResource::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'job_role_id' => $role?->id,
                'role_id' => $resource['role_code'],
                'feature_name' => $resource['feature'],
                'hours' => $resource['hours'],
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
            'resources' => array_map(fn ($r) => [
                'roleId' => $r['role_code'],
                'featureName' => $r['feature'],
                'hours' => $r['hours'],
            ], $resources),
            'overheads' => array_map(fn ($o) => [
                'name' => $o['name'],
                'cost' => $o['cost'],
            ], $overheads),
            'target_margin' => $deal->target_margin,
            'notes' => 'Seeded estimate (Yangon Works demo).',
            'created_by' => $admin->id,
            'created_at' => $deal->created_at?->copy()->subDays(7) ?? Carbon::now()->subDays(7),
        ]);
    }

    /**
     * Salary range across active employees in a given capacity bucket
     * — drives the Ghost Roles table's "Monthly Salary" min–max
     * column on the deal detail page.
     */
    private function salaryRange(array $employees, string $capacityRole): array
    {
        $matches = collect($employees)
            ->filter(fn (Employee $e) => $e->capacity_role === $capacityRole && $e->status === 'Active');

        if ($matches->isEmpty()) {
            return ['min' => 0, 'max' => 0];
        }

        return [
            'min' => (float) $matches->min('monthly_salary'),
            'max' => (float) $matches->max('monthly_salary'),
        ];
    }
}
