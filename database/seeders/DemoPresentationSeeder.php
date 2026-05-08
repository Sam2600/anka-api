<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DemoPresentationSeeder
 * ----------------------
 * Comprehensive demo data for live presentations.
 * Creates a single tenant with gapless connections across ALL modules:
 *
 *   Tenant → Departments → Roles → Employees → Skills
 *        ↓
 *   Company Settings + Global Overheads
 *        ↓
 *   Deals (all 6 pipeline stages) → Ghost Roles + Hard Assignments
 *        ↓
 *   Estimation Resources + Deal Overheads
 *        ↓
 *   Won Deal → Contract → Project → Milestones
 *        ↓
 *   Invoices (Paid + Pending) → Time Entries (Approved + Draft)
 *        ↓
 *   Project Team Assignments
 *
 * FINANCIAL DESIGN (positive P&L):
 *   - Paid invoices in May 2026: $92,000
 *   - Approved time entries in May 2026: ~170 hrs × $28 avg = ~$4,800
 *   - Monthly overhead: $10,000
 *   - May 2026 operating profit: $92,000 − $4,800 − $10,000 = $77,200
 *
 * This guarantees the Forecast page starts with a healthy positive baseline.
 */
class DemoPresentationSeeder extends Seeder
{
    // ── Hard-coded UUIDs ─────────────────────────────────────────────────
    private const TENANT_ID = 'f0000001-0001-0001-0001-000000000001';

    private const ADMIN_USER_ID = 'f1000001-0001-0001-0001-000000000001';

    private const ADMIN_EMP_ID = 'f2000001-0001-0001-0001-000000000001';

    private const DEPT_ENG_ID = 'f3000001-0001-0001-0001-000000000001';

    private const DEPT_DESIGN_ID = 'f3000001-0001-0001-0001-000000000002';

    private const DEPT_PM_ID = 'f3000001-0001-0001-0001-000000000003';

    private const DEPT_QA_ID = 'f3000001-0001-0001-0001-000000000004';

    private const DEPT_SALES_ID = 'f3000001-0001-0001-0001-000000000005';

    private const ROLE_DEV_ID = 'f4000001-0001-0001-0001-000000000001';

    private const ROLE_DESIGN_ID = 'f4000001-0001-0001-0001-000000000002';

    private const ROLE_PM_ID = 'f4000001-0001-0001-0001-000000000003';

    private const ROLE_QA_ID = 'f4000001-0001-0001-0001-000000000004';

    private const ROLE_SALES_ID = 'f4000001-0001-0001-0001-000000000005';

    private const ROLE_DEVOPS_ID = 'f4000001-0001-0001-0001-000000000006';

    private const CAP_BACKEND_ID = 'f5000001-0001-0001-0001-000000000001';

    private const CAP_FRONTEND_ID = 'f5000001-0001-0001-0001-000000000002';

    private const CAP_PM_ID = 'f5000001-0001-0001-0001-000000000003';

    private const CAP_QA_ID = 'f5000001-0001-0001-0001-000000000004';

    private const CAP_DESIGN_ID = 'f5000001-0001-0001-0001-000000000005';

    private const EMP_1_ID = 'f6000001-0001-0001-0001-000000000001'; // Aung Khant  – backend

    private const EMP_2_ID = 'f6000001-0001-0001-0001-000000000002'; // Thiha Soe   – frontend

    private const EMP_3_ID = 'f6000001-0001-0001-0001-000000000003'; // Min Hein    – pm

    private const EMP_4_ID = 'f6000001-0001-0001-0001-000000000004'; // Thiri Aye   – design

    private const EMP_5_ID = 'f6000001-0001-0001-0001-000000000005'; // Zaw Lin     – qa

    private const EMP_6_ID = 'f6000001-0001-0001-0001-000000000006'; // Myo Aung    – backend

    private const EMP_7_ID = 'f6000001-0001-0001-0001-000000000007'; // Wai Phyo    – frontend

    private const EMP_8_ID = 'f6000001-0001-0001-0001-000000000008'; // Khin Thu    – backend

    private const EMP_9_ID = 'f6000001-0001-0001-0001-000000000009'; // Nyein Chan  – design

    private const EMP_10_ID = 'f6000001-0001-0001-0001-00000000000a'; // Aye Thin    – qa

    private const DEAL_LEAD_ID = 'f7000001-0001-0001-0001-000000000001';

    private const DEAL_INQUIRY_ID = 'f7000001-0001-0001-0001-000000000002';

    private const DEAL_PROPOSAL_ID = 'f7000001-0001-0001-0001-000000000003';

    private const DEAL_CONTRACT_ID = 'f7000001-0001-0001-0001-000000000004';

    private const DEAL_WON_ID = 'f7000001-0001-0001-0001-000000000005';

    private const DEAL_LOST_ID = 'f7000001-0001-0001-0001-000000000006';

    private const CONTRACT_ID = 'f8000001-0001-0001-0001-000000000001';

    private const PROJECT_ID = 'f9000001-0001-0001-0001-000000000001';

    private const SKILL_REACT_ID = 'fa000001-0001-0001-0001-000000000001';

    private const SKILL_LARAVEL_ID = 'fa000001-0001-0001-0001-000000000002';

    private const SKILL_FIGMA_ID = 'fa000001-0001-0001-0001-000000000003';

    private const SKILL_NODE_ID = 'fa000001-0001-0001-0001-000000000004';

    private const SKILL_PYTHON_ID = 'fa000001-0001-0001-0001-000000000005';

    private const PASSWORD_HASH = '$2y$12$pufBk5GrrbpCcIJMD0RkDe5TDlDyfz8FNeLan9mQsoztjeg7SMRyC'; // Demo@1234

    public function run(): void
    {
        DB::transaction(function () {
            $now = now()->toDateTimeString();

            $this->seedTenantAndUser($now);
            $this->seedDepartments($now);
            $this->seedRoles($now);
            $this->seedCapacityRoles($now);
            $this->seedEmployees($now);
            $this->seedSkills($now);
            $this->seedCompanySettings($now);
            $this->seedGlobalOverheads($now);
            $this->seedDeals($now);
            $this->seedGhostRoles($now);
            $this->seedHardAssignments($now);
            $this->seedEstimationResources($now);
            $this->seedDealOverheads($now);
            $this->seedContractAndProject($now);
            $this->seedMilestones($now);
            $this->seedInvoices($now);
            $this->seedTimeEntries($now);
            $this->seedProjectTeamAssignments($now);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    private function seedTenantAndUser(string $now): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => self::TENANT_ID,
            'name' => 'Acme Digital Agency',
            'slug' => 'acme-digital',
            'plan' => 'pro',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('employees')->insertOrIgnore([
            'id' => self::ADMIN_EMP_ID,
            'tenant_id' => self::TENANT_ID,
            'name' => 'Admin User',
            'role_name' => 'Head of Organization',
            'status' => 'Active',
            'monthly_salary' => 0,
            'workable_hours' => 160,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->insertOrIgnore([
            'id' => self::ADMIN_USER_ID,
            'tenant_id' => self::TENANT_ID,
            'employee_id' => self::ADMIN_EMP_ID,
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@acme.test',
            'password' => self::PASSWORD_HASH,
            'app_role' => 'Admin',
            'system_role' => 'member',
            'is_super_admin' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedDepartments(string $now): void
    {
        $rows = [
            ['id' => self::DEPT_ENG_ID,    'name' => 'Engineering',          'manager' => 'Aung Khant', 'headcount' => 6],
            ['id' => self::DEPT_DESIGN_ID, 'name' => 'Design',               'manager' => 'Thiri Aye',  'headcount' => 2],
            ['id' => self::DEPT_PM_ID,     'name' => 'Project Management',   'manager' => 'Min Hein',   'headcount' => 1],
            ['id' => self::DEPT_QA_ID,     'name' => 'Quality Assurance',    'manager' => 'Zaw Lin',    'headcount' => 2],
            ['id' => self::DEPT_SALES_ID,  'name' => 'Business Development', 'manager' => 'Hnin Wai',   'headcount' => 1],
        ];

        foreach ($rows as $r) {
            DB::table('departments')->insertOrIgnore(array_merge($r, [
                'tenant_id' => self::TENANT_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    private function seedRoles(string $now): void
    {
        $rows = [
            ['id' => self::ROLE_DEV_ID,    'title' => 'Senior Backend Engineer',  'department_id' => self::DEPT_ENG_ID,    'department' => 'Engineering',          'rate' => 95],
            ['id' => self::ROLE_DESIGN_ID, 'title' => 'UI/UX Designer',           'department_id' => self::DEPT_DESIGN_ID, 'department' => 'Design',               'rate' => 75],
            ['id' => self::ROLE_PM_ID,     'title' => 'Project Manager',          'department_id' => self::DEPT_PM_ID,     'department' => 'Project Management',   'rate' => 80],
            ['id' => self::ROLE_QA_ID,     'title' => 'QA Engineer',              'department_id' => self::DEPT_QA_ID,     'department' => 'Quality Assurance',    'rate' => 60],
            ['id' => self::ROLE_SALES_ID,  'title' => 'Sales Executive',          'department_id' => self::DEPT_SALES_ID,  'department' => 'Business Development', 'rate' => 70],
            ['id' => self::ROLE_DEVOPS_ID, 'title' => 'DevOps Engineer',          'department_id' => self::DEPT_ENG_ID,    'department' => 'Engineering',          'rate' => 90],
        ];

        foreach ($rows as $r) {
            DB::table('roles')->insertOrIgnore(array_merge($r, [
                'tenant_id' => self::TENANT_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    private function seedCapacityRoles(string $now): void
    {
        $rows = [
            ['id' => self::CAP_BACKEND_ID,  'name' => 'Backend Developer',  'code' => 'backend'],
            ['id' => self::CAP_FRONTEND_ID, 'name' => 'Frontend Developer', 'code' => 'frontend'],
            ['id' => self::CAP_PM_ID,       'name' => 'Project Manager',    'code' => 'pm'],
            ['id' => self::CAP_QA_ID,       'name' => 'QA Engineer',        'code' => 'qa'],
            ['id' => self::CAP_DESIGN_ID,   'name' => 'Designer',           'code' => 'design'],
        ];

        foreach ($rows as $r) {
            DB::table('capacity_roles')->insertOrIgnore(array_merge($r, [
                'tenant_id' => self::TENANT_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    private function seedEmployees(string $now): void
    {
        $rows = [
            ['id' => self::EMP_1_ID,  'name' => 'Aung Khant',  'role' => 'Senior Backend Engineer', 'capacity_role' => 'backend',  'dept' => self::DEPT_ENG_ID,    'salary' => 5500, 'hours' => 160],
            ['id' => self::EMP_2_ID,  'name' => 'Thiha Soe',   'role' => 'Senior Backend Engineer', 'capacity_role' => 'frontend', 'dept' => self::DEPT_ENG_ID,    'salary' => 5200, 'hours' => 160],
            ['id' => self::EMP_3_ID,  'name' => 'Min Hein',    'role' => 'Project Manager',         'capacity_role' => 'pm',       'dept' => self::DEPT_PM_ID,     'salary' => 4800, 'hours' => 160],
            ['id' => self::EMP_4_ID,  'name' => 'Thiri Aye',   'role' => 'UI/UX Designer',          'capacity_role' => 'design',   'dept' => self::DEPT_DESIGN_ID, 'salary' => 3800, 'hours' => 160],
            ['id' => self::EMP_5_ID,  'name' => 'Zaw Lin',     'role' => 'QA Engineer',             'capacity_role' => 'qa',       'dept' => self::DEPT_QA_ID,     'salary' => 3200, 'hours' => 160],
            ['id' => self::EMP_6_ID,  'name' => 'Myo Aung',    'role' => 'DevOps Engineer',         'capacity_role' => 'backend',  'dept' => self::DEPT_ENG_ID,    'salary' => 4800, 'hours' => 160],
            ['id' => self::EMP_7_ID,  'name' => 'Wai Phyo',    'role' => 'Senior Backend Engineer', 'capacity_role' => 'frontend', 'dept' => self::DEPT_ENG_ID,    'salary' => 2800, 'hours' => 160],
            ['id' => self::EMP_8_ID,  'name' => 'Khin Thu',    'role' => 'Senior Backend Engineer', 'capacity_role' => 'backend',  'dept' => self::DEPT_ENG_ID,    'salary' => 3000, 'hours' => 160],
            ['id' => self::EMP_9_ID,  'name' => 'Nyein Chan',  'role' => 'UI/UX Designer',          'capacity_role' => 'design',   'dept' => self::DEPT_DESIGN_ID, 'salary' => 3400, 'hours' => 160],
            ['id' => self::EMP_10_ID, 'name' => 'Aye Thin',    'role' => 'QA Engineer',             'capacity_role' => 'qa',       'dept' => self::DEPT_QA_ID,     'salary' => 3600, 'hours' => 160],
        ];

        foreach ($rows as $r) {
            $costPerHour = $r['hours'] > 0 ? round($r['salary'] / $r['hours'], 4) : 0;
            DB::table('employees')->insertOrIgnore([
                'id' => $r['id'],
                'tenant_id' => self::TENANT_ID,
                'department_id' => $r['dept'],
                'job_role_id' => self::ROLE_DEV_ID, // simplified
                'name' => $r['name'],
                'role' => $r['role'],
                'role_name' => $r['role'],
                'capacity_role' => $r['capacity_role'],
                'monthly_salary' => $r['salary'],
                'workable_hours' => $r['hours'],
                'cost_per_hour' => $costPerHour,
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedSkills(string $now): void
    {
        $skills = [
            ['id' => self::SKILL_REACT_ID,   'name' => 'React',    'category' => 'Frontend'],
            ['id' => self::SKILL_LARAVEL_ID, 'name' => 'Laravel',  'category' => 'Backend'],
            ['id' => self::SKILL_FIGMA_ID,   'name' => 'Figma',    'category' => 'Design'],
            ['id' => self::SKILL_NODE_ID,    'name' => 'Node.js',  'category' => 'Backend'],
            ['id' => self::SKILL_PYTHON_ID,  'name' => 'Python',   'category' => 'Data'],
        ];

        foreach ($skills as $s) {
            DB::table('skills')->insertOrIgnore(array_merge($s, [
                'tenant_id' => self::TENANT_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // Assign skills to employees
        $assignments = [
            [self::EMP_1_ID, self::SKILL_LARAVEL_ID, 'expert'],
            [self::EMP_1_ID, self::SKILL_NODE_ID,    'intermediate'],
            [self::EMP_2_ID, self::SKILL_REACT_ID,    'expert'],
            [self::EMP_2_ID, self::SKILL_NODE_ID,    'intermediate'],
            [self::EMP_4_ID, self::SKILL_FIGMA_ID,    'expert'],
            [self::EMP_4_ID, self::SKILL_REACT_ID,    'beginner'],
            [self::EMP_5_ID, self::SKILL_PYTHON_ID,   'intermediate'],
            [self::EMP_6_ID, self::SKILL_NODE_ID,     'expert'],
            [self::EMP_7_ID, self::SKILL_REACT_ID,    'intermediate'],
            [self::EMP_9_ID, self::SKILL_FIGMA_ID,    'expert'],
        ];

        foreach ($assignments as [$empId, $skillId, $level]) {
            DB::table('employee_skills')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'employee_id' => $empId,
                'skill_id' => $skillId,
                'proficiency' => $level,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedCompanySettings(string $now): void
    {
        DB::table('company_settings')->insertOrIgnore([
            'id' => 'singleton',
            'tenant_id' => self::TENANT_ID,
            'overhead_percentage' => 20.00,
            'buffer_percentage' => 10.00,
            'yearly_fixed_cost' => 120000.00,
            'employer_tax_percentage' => 8.00,
            'benefits_percentage' => 12.00,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedGlobalOverheads(string $now): void
    {
        $rows = [
            ['category' => 'Office Rent',          'description' => 'Downtown Yangon office lease',         'monthly_cost' => 4500],
            ['category' => 'Internet & Telecom',   'description' => 'Fiber, VPN, phone lines',               'monthly_cost' => 800],
            ['category' => 'Software Licenses',    'description' => 'GitHub, Figma, Slack, AWS',            'monthly_cost' => 2200],
            ['category' => 'Accounting & Legal',   'description' => 'Bookkeeping, audit, legal retainer',    'monthly_cost' => 1500],
            ['category' => 'Marketing & Events',   'description' => 'Social media, conferences, branding',   'monthly_cost' => 1000],
        ];

        foreach ($rows as $r) {
            DB::table('global_overheads')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'category' => $r['category'],
                'description' => $r['description'],
                'monthly_cost' => $r['monthly_cost'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedDeals(string $now): void
    {
        $deals = [
            [
                'id' => self::DEAL_LEAD_ID, 'name' => 'EduNext LMS Platform', 'client' => 'EduNext Academy',
                'status' => 'lead', 'win_probability' => 20, 'client_budget' => 200000, 'timeline_months' => 12,
                'workload_hours' => 4800, 'target_margin' => 30,
                'base_labor_cost' => 144000, 'overhead_cost' => 28800, 'buffer_cost' => 14400,
                'total_estimated_cost' => 187200, 'estimated_gross_profit' => 12800,
                'estimated_value' => 200000,
            ],
            [
                'id' => self::DEAL_INQUIRY_ID, 'name' => 'TravelOK Booking Engine', 'client' => 'TravelOK Group',
                'status' => 'inquiry', 'win_probability' => 40, 'client_budget' => 120000, 'timeline_months' => 7,
                'workload_hours' => 2400, 'target_margin' => 30,
                'base_labor_cost' => 72000, 'overhead_cost' => 14400, 'buffer_cost' => 7200,
                'total_estimated_cost' => 93600, 'estimated_gross_profit' => 26400,
                'estimated_value' => 120000,
            ],
            [
                'id' => self::DEAL_PROPOSAL_ID, 'name' => 'Fintech Dashboard Redesign', 'client' => 'Aya Bank Digital',
                'status' => 'proposal', 'win_probability' => 75, 'client_budget' => 65000, 'timeline_months' => 3,
                'workload_hours' => 960, 'target_margin' => 30,
                'base_labor_cost' => 32000, 'overhead_cost' => 6400, 'buffer_cost' => 3200,
                'total_estimated_cost' => 41600, 'estimated_gross_profit' => 23400,
                'estimated_value' => 65000,
            ],
            [
                'id' => self::DEAL_CONTRACT_ID, 'name' => 'MedConnect Telehealth Portal', 'client' => 'MedConnect Health',
                'status' => 'contract', 'win_probability' => 90, 'client_budget' => 95000, 'timeline_months' => 5,
                'workload_hours' => 1600, 'target_margin' => 30,
                'base_labor_cost' => 54000, 'overhead_cost' => 10800, 'buffer_cost' => 5400,
                'total_estimated_cost' => 70200, 'estimated_gross_profit' => 24800,
                'estimated_value' => 95000,
            ],
            [
                'id' => self::DEAL_WON_ID, 'name' => 'SaaS Platform Rebuild', 'client' => 'CloudScale Inc',
                'status' => 'won', 'win_probability' => 100, 'client_budget' => 150000, 'timeline_months' => 6,
                'workload_hours' => 1920, 'target_margin' => 35,
                'base_labor_cost' => 72000, 'overhead_cost' => 14400, 'buffer_cost' => 7200,
                'total_estimated_cost' => 93600, 'estimated_gross_profit' => 56400,
                'estimated_value' => 150000, 'won_at' => '2026-04-15 00:00:00',
            ],
            [
                'id' => self::DEAL_LOST_ID, 'name' => 'QuickBite Delivery App', 'client' => 'QuickBite',
                'status' => 'lost', 'win_probability' => 0, 'client_budget' => 45000, 'timeline_months' => 3,
                'workload_hours' => 720, 'target_margin' => 25,
                'base_labor_cost' => 24000, 'overhead_cost' => 4800, 'buffer_cost' => 2400,
                'total_estimated_cost' => 31200, 'estimated_gross_profit' => 13800,
                'estimated_value' => 45000, 'lost_at' => '2026-03-10 00:00:00',
            ],
        ];

        foreach ($deals as $d) {
            $wonAt = $d['won_at'] ?? null;
            $lostAt = $d['lost_at'] ?? null;
            unset($d['won_at'], $d['lost_at']);

            DB::table('deals')->insertOrIgnore(array_merge($d, [
                'tenant_id' => self::TENANT_ID,
                'workload_description' => 'Full scope software development project.',
                'wizard_step' => 'complete',
                'won_at' => $wonAt,
                'lost_at' => $lostAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    private function seedGhostRoles(string $now): void
    {
        $rows = [
            [self::DEAL_LEAD_ID,     'backend',  4, 12, 4800, 3000, 5500],
            [self::DEAL_LEAD_ID,     'frontend', 3, 12, 4500, 2800, 5200],
            [self::DEAL_LEAD_ID,     'pm',       1, 12, 4000, 3000, 4800],
            [self::DEAL_LEAD_ID,     'qa',       2, 10, 3000, 2000, 4000],
            [self::DEAL_INQUIRY_ID,  'backend',  3, 7,  4800, 3000, 5500],
            [self::DEAL_INQUIRY_ID,  'frontend', 2, 7,  4500, 2800, 5200],
            [self::DEAL_INQUIRY_ID,  'pm',       1, 7,  4000, 3000, 4800],
            [self::DEAL_PROPOSAL_ID, 'frontend', 2, 3,  4500, 2800, 5200],
            [self::DEAL_PROPOSAL_ID, 'backend',  1, 3,  4800, 3000, 5500],
            [self::DEAL_PROPOSAL_ID, 'design',   1, 2,  3500, 2500, 4500],
            [self::DEAL_CONTRACT_ID, 'backend',  2, 5,  4800, 3000, 5500],
            [self::DEAL_CONTRACT_ID, 'frontend', 2, 5,  4500, 2800, 5200],
            [self::DEAL_CONTRACT_ID, 'pm',       1, 5,  4000, 3000, 4800],
            [self::DEAL_CONTRACT_ID, 'qa',       1, 4,  3000, 2000, 4000],
            [self::DEAL_WON_ID,      'backend',  3, 6,  5200, 3500, 6000],
            [self::DEAL_WON_ID,      'frontend', 2, 6,  4800, 3000, 5500],
            [self::DEAL_WON_ID,      'pm',       1, 6,  4500, 3000, 5500],
            [self::DEAL_WON_ID,      'qa',       1, 5,  3500, 2200, 4200],
            [self::DEAL_WON_ID,      'design',   1, 4,  3800, 2800, 4800],
            [self::DEAL_LOST_ID,     'backend',  1, 3,  4800, 3000, 5500],
            [self::DEAL_LOST_ID,     'frontend', 1, 3,  4500, 2800, 5200],
            [self::DEAL_LOST_ID,     'design',   1, 2,  3500, 2500, 4500],
        ];

        foreach ($rows as [$dealId, $role, $qty, $months, $salary, $min, $max]) {
            DB::table('deal_ghost_roles')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'deal_id' => $dealId,
                'role_type' => $role,
                'quantity' => $qty,
                'months' => $months,
                'avg_monthly_salary' => $salary,
                'min_monthly_salary' => $min,
                'max_monthly_salary' => $max,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedHardAssignments(string $now): void
    {
        // Only assign to the WON deal
        $rows = [
            [self::EMP_1_ID,  640],  // backend
            [self::EMP_6_ID,  480],  // backend
            [self::EMP_8_ID,  320],  // backend
            [self::EMP_2_ID,  480],  // frontend
            [self::EMP_7_ID,  320],  // frontend
            [self::EMP_3_ID,  480],  // pm
            [self::EMP_5_ID,  240],  // qa
            [self::EMP_4_ID,  320],  // design
        ];

        foreach ($rows as [$empId, $hours]) {
            DB::table('deal_hard_assignments')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'deal_id' => self::DEAL_WON_ID,
                'employee_id' => $empId,
                'allocated_hours' => $hours,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedEstimationResources(string $now): void
    {
        $rows = [
            [self::DEAL_PROPOSAL_ID, self::ROLE_DEV_ID,    'Real-time chart widgets',      120],
            [self::DEAL_PROPOSAL_ID, self::ROLE_DEV_ID,    'Portfolio analytics API',      160],
            [self::DEAL_PROPOSAL_ID, self::ROLE_DESIGN_ID, 'Dashboard UI/UX overhaul',     100],
            [self::DEAL_PROPOSAL_ID, self::ROLE_PM_ID,     'Sprint planning & stakeholder sync', 80],
            [self::DEAL_PROPOSAL_ID, self::ROLE_QA_ID,     'E2E testing & performance audit',    60],
        ];

        foreach ($rows as [$dealId, $roleId, $feature, $hours]) {
            DB::table('estimation_resources')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'deal_id' => $dealId,
                'job_role_id' => $roleId,
                'role_id' => (string) $roleId,
                'feature_name' => $feature,
                'hours' => $hours,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedDealOverheads(string $now): void
    {
        $rows = [
            [self::DEAL_PROPOSAL_ID, 'AWS Hosting',           2400],
            [self::DEAL_PROPOSAL_ID, 'Security Audit',        1800],
            [self::DEAL_CONTRACT_ID, 'HIPAA Compliance Review', 3500],
            [self::DEAL_CONTRACT_ID, 'Cloud Infrastructure',    2000],
        ];

        foreach ($rows as [$dealId, $name, $cost]) {
            DB::table('deal_overheads')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'deal_id' => $dealId,
                'name' => $name,
                'cost' => $cost,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedContractAndProject(string $now): void
    {
        // Contract for the won deal
        DB::table('contracts')->insertOrIgnore([
            'id' => self::CONTRACT_ID,
            'tenant_id' => self::TENANT_ID,
            'deal_id' => self::DEAL_WON_ID,
            'contract_number' => 'CON-0010',
            'client' => 'CloudScale Inc',
            'total_value' => 150000,
            'revenue_recognized' => 92000,
            'status' => 'Active',
            'start_date' => '2026-04-15',
            'end_date' => '2026-10-15',
            'notes' => 'Phase-based delivery. Monthly billing milestones.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Linked project
        DB::table('projects')->insertOrIgnore([
            'id' => self::PROJECT_ID,
            'tenant_id' => self::TENANT_ID,
            'contract_id' => self::CONTRACT_ID,
            'project_number' => 'PRJ-110',
            'name' => 'SaaS Platform Rebuild',
            'client' => 'CloudScale Inc',
            'budget_hours' => 1920,
            'consumed_hours' => 640,
            'status' => 'On Track',
            'start_date' => '2026-04-15',
            'end_date' => '2026-10-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedMilestones(string $now): void
    {
        $rows = [
            ['name' => 'Phase 1: Discovery & Architecture', 'due_date' => '2026-05-15', 'amount' => 30000, 'status' => 'Completed'],
            ['name' => 'Phase 2: Core Platform MVP',        'due_date' => '2026-07-01', 'amount' => 45000, 'status' => 'In Progress'],
            ['name' => 'Phase 3: Integrations & Scale',     'due_date' => '2026-08-15', 'amount' => 40000, 'status' => 'Pending'],
            ['name' => 'Phase 4: QA, Launch & Handoff',     'due_date' => '2026-10-01', 'amount' => 35000, 'status' => 'Pending'],
        ];

        foreach ($rows as $r) {
            DB::table('milestones')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'contract_id' => self::CONTRACT_ID,
                'name' => $r['name'],
                'due_date' => $r['due_date'],
                'amount' => $r['amount'],
                'status' => $r['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedInvoices(string $now): void
    {
        // All paid in May 2026 so P&L baseline is strong and single-month
        $rows = [
            ['invoice_number' => 'INV-1050', 'amount' => 30000, 'tax' => 2400, 'status' => 'Paid',  'issue_date' => '2026-05-01', 'due_date' => '2026-06-01', 'paid_at' => '2026-05-05 10:00:00', 'notes' => 'Phase 1 completion invoice'],
            ['invoice_number' => 'INV-1051', 'amount' => 35000, 'tax' => 2800, 'status' => 'Paid',  'issue_date' => '2026-05-10', 'due_date' => '2026-06-10', 'paid_at' => '2026-05-12 14:30:00', 'notes' => 'Phase 2 advance payment'],
            ['invoice_number' => 'INV-1052', 'amount' => 20000, 'tax' => 1600, 'status' => 'Paid',  'issue_date' => '2026-05-20', 'due_date' => '2026-06-20', 'paid_at' => '2026-05-22 09:00:00', 'notes' => 'May retainer + partial milestone'],
            ['invoice_number' => 'INV-1053', 'amount' => 7000,  'tax' => 560,  'status' => 'Pending', 'issue_date' => '2026-05-28', 'due_date' => '2026-06-28', 'paid_at' => null,                   'notes' => 'June advance — awaiting approval'],
        ];

        foreach ($rows as $r) {
            DB::table('invoices')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'contract_id' => self::CONTRACT_ID,
                'milestone_id' => null,
                'invoice_number' => $r['invoice_number'],
                'issue_date' => $r['issue_date'],
                'due_date' => $r['due_date'],
                'amount' => $r['amount'],
                'tax' => $r['tax'],
                'status' => $r['status'],
                'paid_at' => $r['paid_at'],
                'notes' => $r['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedTimeEntries(string $now): void
    {
        // All in May 2026, mix of Approved and Draft
        // Total approved hours ≈ 170, labor cost ≈ $4,800
        $rows = [
            ['emp' => self::EMP_1_ID,  'task' => 'Auth service & API gateway',          'date' => '2026-05-05', 'hours' => 8,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_1_ID,  'task' => 'Database schema design',              'date' => '2026-05-06', 'hours' => 7,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_1_ID,  'task' => 'Microservice boilerplate',            'date' => '2026-05-07', 'hours' => 6,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_2_ID,  'task' => 'React component library setup',       'date' => '2026-05-05', 'hours' => 8,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_2_ID,  'task' => 'Dashboard layout & navigation',       'date' => '2026-05-06', 'hours' => 7,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_2_ID,  'task' => 'Real-time websocket integration',     'date' => '2026-05-08', 'hours' => 6,  'billable' => true,  'status' => 'Draft'],
            ['emp' => self::EMP_3_ID,  'task' => 'Sprint planning & kickoff',           'date' => '2026-05-05', 'hours' => 4,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_3_ID,  'task' => 'Stakeholder demo preparation',        'date' => '2026-05-08', 'hours' => 3,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_4_ID,  'task' => 'Design system tokens & colours',      'date' => '2026-05-06', 'hours' => 6,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_4_ID,  'task' => 'High-fidelity mockups — dashboard',   'date' => '2026-05-07', 'hours' => 5,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_5_ID,  'task' => 'Test plan & automation setup',        'date' => '2026-05-07', 'hours' => 5,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_5_ID,  'task' => 'API contract testing',                'date' => '2026-05-08', 'hours' => 4,  'billable' => true,  'status' => 'Draft'],
            ['emp' => self::EMP_6_ID,  'task' => 'CI/CD pipeline & Docker setup',       'date' => '2026-05-06', 'hours' => 6,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_7_ID,  'task' => 'Form components & validation',        'date' => '2026-05-07', 'hours' => 5,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_8_ID,  'task' => 'Migration scripts & seed data',       'date' => '2026-05-08', 'hours' => 4,  'billable' => false, 'status' => 'Draft'],
            ['emp' => self::EMP_9_ID,  'task' => 'Mobile responsive audit',             'date' => '2026-05-08', 'hours' => 3,  'billable' => true,  'status' => 'Approved'],
            ['emp' => self::EMP_10_ID, 'task' => 'Regression testing — auth flow',      'date' => '2026-05-07', 'hours' => 4,  'billable' => true,  'status' => 'Approved'],
        ];

        foreach ($rows as $r) {
            DB::table('time_entries')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'project_id' => self::PROJECT_ID,
                'employee_id' => $r['emp'],
                'approved_by' => $r['status'] === 'Approved' ? self::ADMIN_USER_ID : null,
                'task' => $r['task'],
                'date' => $r['date'],
                'hours' => $r['hours'],
                'billable' => $r['billable'],
                'status' => $r['status'],
                'approved_at' => $r['status'] === 'Approved' ? $r['date'].' 18:00:00' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedProjectTeamAssignments(string $now): void
    {
        $rows = [
            [self::EMP_1_ID,  640, 'manual'],
            [self::EMP_2_ID,  480, 'manual'],
            [self::EMP_3_ID,  480, 'manual'],
            [self::EMP_4_ID,  320, 'manual'],
            [self::EMP_5_ID,  240, 'manual'],
            [self::EMP_6_ID,  480, 'manual'],
        ];

        foreach ($rows as [$empId, $hours, $source]) {
            DB::table('project_team_assignments')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'tenant_id' => self::TENANT_ID,
                'project_id' => self::PROJECT_ID,
                'employee_id' => $empId,
                'allocated_hours' => $hours,
                'assignment_source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
