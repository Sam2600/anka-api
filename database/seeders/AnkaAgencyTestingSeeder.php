<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AnkaAgencyTestingSeeder
 * ----------------------
 * Testing-focused realistic data for ANKA Agency tenant.
 * 
 * Design:
 *   - 20 Employees (salary range: Ks500,000 – Ks5,000,000)
 *   - 6 Deals (4 profitable, 2 not profitable)
 *   - 3 Projects (linked to won contracts)
 *   - Currency: MMK
 *   - Real Myanmar business context
 */
class AnkaAgencyTestingSeeder extends Seeder
{
    private string $tenantId = 'aa24b68f-9de2-4621-b404-fb3edd318ee6';
    private string $now;

    public function run(): void
    {
        $this->now = now()->toDateTimeString();

        DB::transaction(function () {
            $this->wipeTenantData();
            $this->seedDepartments();
            $this->seedRoles();
            $this->seedCapacityRoles();
            $this->seedEmployees();
            $this->seedSkills();
            $this->seedCompanySettings();
            $this->seedGlobalOverheads();
            $this->seedDeals();
            $this->seedContractsAndProjects();
            $this->seedMilestones();
            $this->seedInvoices();
            $this->seedTimeEntries();
            $this->seedProjectTeamAssignments();
        });
    }

    private function wipeTenantData(): void
    {
        $tables = [
            'project_team_assignments', 'time_entries', 'invoices', 'milestones',
            'projects', 'contracts', 'deal_hard_assignments', 'deal_ghost_roles',
            'deal_overheads', 'estimation_resources', 'estimation_versions', 'deals',
            'employee_skills', 'employees', 'skills', 'capacity_roles', 'roles',
            'global_overheads', 'company_settings', 'departments',
        ];

        foreach ($tables as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    }

    private function seedDepartments(): void
    {
        $depts = [
            ['name' => 'Engineering', 'manager' => 'Aung Khant', 'headcount' => 10],
            ['name' => 'Design', 'manager' => 'Thiri Aye', 'headcount' => 3],
            ['name' => 'Project Management', 'manager' => 'Min Hein', 'headcount' => 3],
            ['name' => 'Quality Assurance', 'manager' => 'Zaw Lin', 'headcount' => 2],
            ['name' => 'Business Development', 'manager' => 'Hnin Wai', 'headcount' => 2],
        ];

        foreach ($depts as $d) {
            DB::table('departments')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'name' => $d['name'],
                'manager' => $d['manager'],
                'headcount' => $d['headcount'],
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedRoles(): void
    {
        $deptIds = DB::table('departments')->where('tenant_id', $this->tenantId)->pluck('id', 'name')->toArray();

        $roles = [
            ['title' => 'Principal Software Engineer', 'department_id' => $deptIds['Engineering'], 'department' => 'Engineering', 'rate' => 5000000],
            ['title' => 'Senior Software Engineer', 'department_id' => $deptIds['Engineering'], 'department' => 'Engineering', 'rate' => 2500000],
            ['title' => 'Software Engineer', 'department_id' => $deptIds['Engineering'], 'department' => 'Engineering', 'rate' => 1200000],
            ['title' => 'Junior Software Engineer', 'department_id' => $deptIds['Engineering'], 'department' => 'Engineering', 'rate' => 600000],
            ['title' => 'UI/UX Designer', 'department_id' => $deptIds['Design'], 'department' => 'Design', 'rate' => 900000],
            ['title' => 'Project Manager', 'department_id' => $deptIds['Project Management'], 'department' => 'Project Management', 'rate' => 1500000],
            ['title' => 'QA Engineer', 'department_id' => $deptIds['Quality Assurance'], 'department' => 'Quality Assurance', 'rate' => 700000],
            ['title' => 'Business Development Executive', 'department_id' => $deptIds['Business Development'], 'department' => 'Business Development', 'rate' => 1000000],
        ];

        foreach ($roles as $r) {
            DB::table('roles')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'department_id' => $r['department_id'],
                'title' => $r['title'],
                'department' => $r['department'],
                'rate' => $r['rate'],
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedCapacityRoles(): void
    {
        foreach (['frontend', 'backend', 'pm', 'qa', 'design'] as $code) {
            DB::table('capacity_roles')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'name' => ucfirst($code) . ' Engineer',
                'code' => $code,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedEmployees(): void
    {
        $deptIds = DB::table('departments')->where('tenant_id', $this->tenantId)->pluck('id', 'name')->toArray();
        $roleIds = DB::table('roles')->where('tenant_id', $this->tenantId)->pluck('id', 'title')->toArray();

        $employees = [
            // Principal / Tech Lead (Ks5M - Ks3M)
            ['name' => 'Aung Khant', 'role' => 'Principal Software Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 5000000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Thiha Soe', 'role' => 'Principal Software Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 4500000, 'hours' => 160, 'status' => 'Active'],

            // Senior (Ks2.5M - Ks1.5M)
            ['name' => 'Myo Aung', 'role' => 'Senior Software Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 2500000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Wai Phyo', 'role' => 'Senior Software Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 2200000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Khin Thu', 'role' => 'Senior Software Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 2000000, 'hours' => 160, 'status' => 'Active'],

            // Mid-level (Ks1.5M - Ks1M)
            ['name' => 'Nyein Chan', 'role' => 'Software Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 1500000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Zaw Min', 'role' => 'Software Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 1300000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Kaung Htet', 'role' => 'Software Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 1200000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Phone Myat', 'role' => 'Software Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 1000000, 'hours' => 160, 'status' => 'On Leave'],

            // Junior (Ks800K - Ks500K)
            ['name' => 'Soe Naing', 'role' => 'Junior Software Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 800000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Zay Yar', 'role' => 'Junior Software Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 650000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Thura', 'role' => 'Junior Software Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 500000, 'hours' => 160, 'status' => 'Active'],

            // Design (Ks900K - Ks600K)
            ['name' => 'Thiri Aye', 'role' => 'UI/UX Designer', 'capacity_role' => 'design', 'dept' => 'Design', 'salary' => 900000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Su Mon', 'role' => 'UI/UX Designer', 'capacity_role' => 'design', 'dept' => 'Design', 'salary' => 750000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Ei Phyo', 'role' => 'UI/UX Designer', 'capacity_role' => 'design', 'dept' => 'Design', 'salary' => 600000, 'hours' => 160, 'status' => 'Active'],

            // PM (Ks2M - Ks1.2M)
            ['name' => 'Min Hein', 'role' => 'Project Manager', 'capacity_role' => 'pm', 'dept' => 'Project Management', 'salary' => 2000000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Aye Thin', 'role' => 'Project Manager', 'capacity_role' => 'pm', 'dept' => 'Project Management', 'salary' => 1500000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Phyu Phyu', 'role' => 'Project Manager', 'capacity_role' => 'pm', 'dept' => 'Project Management', 'salary' => 1200000, 'hours' => 160, 'status' => 'Active'],

            // QA (Ks700K - Ks500K)
            ['name' => 'Zaw Lin', 'role' => 'QA Engineer', 'capacity_role' => 'qa', 'dept' => 'Quality Assurance', 'salary' => 700000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'May Thu', 'role' => 'QA Engineer', 'capacity_role' => 'qa', 'dept' => 'Quality Assurance', 'salary' => 500000, 'hours' => 160, 'status' => 'Active'],

            // BD (Ks1.2M - Ks800K)
            ['name' => 'Hnin Wai', 'role' => 'Business Development Executive', 'capacity_role' => null, 'dept' => 'Business Development', 'salary' => 1200000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Ko Ko', 'role' => 'Business Development Executive', 'capacity_role' => null, 'dept' => 'Business Development', 'salary' => 800000, 'hours' => 160, 'status' => 'Active'],
        ];

        foreach ($employees as $emp) {
            $costPerHour = $emp['hours'] > 0 ? round($emp['salary'] / $emp['hours'], 4) : 0;
            DB::table('employees')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'department_id' => $deptIds[$emp['dept']] ?? null,
                'job_role_id' => $roleIds[$emp['role']] ?? null,
                'name' => $emp['name'],
                'role' => $emp['role'],
                'role_name' => $emp['role'],
                'capacity_role' => $emp['capacity_role'],
                'monthly_salary' => $emp['salary'],
                'workable_hours' => $emp['hours'],
                'cost_per_hour' => $costPerHour,
                'status' => $emp['status'],
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedSkills(): void
    {
        $skills = [
            ['name' => 'React', 'category' => 'Frontend'],
            ['name' => 'Vue.js', 'category' => 'Frontend'],
            ['name' => 'Next.js', 'category' => 'Frontend'],
            ['name' => 'TypeScript', 'category' => 'Frontend'],
            ['name' => 'Tailwind CSS', 'category' => 'Frontend'],
            ['name' => 'Laravel', 'category' => 'Backend'],
            ['name' => 'Node.js', 'category' => 'Backend'],
            ['name' => 'PostgreSQL', 'category' => 'Backend'],
            ['name' => 'Redis', 'category' => 'Backend'],
            ['name' => 'Docker', 'category' => 'DevOps'],
            ['name' => 'Figma', 'category' => 'Design'],
            ['name' => 'UI/UX Design', 'category' => 'Design'],
            ['name' => 'Scrum', 'category' => 'Management'],
            ['name' => 'Agile', 'category' => 'Management'],
            ['name' => 'Manual Testing', 'category' => 'QA'],
            ['name' => 'Automated Testing', 'category' => 'QA'],
        ];

        $skillIds = [];
        foreach ($skills as $s) {
            $id = Str::uuid()->toString();
            $skillIds[$s['name']] = $id;
            DB::table('skills')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'name' => $s['name'], 'category' => $s['category'], 'created_at' => $this->now, 'updated_at' => $this->now]);
        }

        $employeeSkills = [
            'Aung Khant' => ['Laravel', 'Node.js', 'PostgreSQL', 'Docker', 'Redis'],
            'Thiha Soe' => ['React', 'Next.js', 'TypeScript', 'Tailwind CSS', 'Vue.js'],
            'Myo Aung' => ['Laravel', 'React', 'PostgreSQL', 'Docker'],
            'Wai Phyo' => ['React', 'TypeScript', 'Tailwind CSS', 'Next.js'],
            'Khin Thu' => ['Laravel', 'Redis', 'Docker', 'Node.js'],
            'Nyein Chan' => ['React', 'Vue.js', 'TypeScript'],
            'Zaw Min' => ['Node.js', 'PostgreSQL', 'Redis', 'Docker'],
            'Kaung Htet' => ['React', 'Vue.js', 'Next.js', 'Tailwind CSS'],
            'Phone Myat' => ['Laravel', 'Node.js', 'PostgreSQL'],
            'Soe Naing' => ['TypeScript', 'Tailwind CSS', 'React'],
            'Zay Yar' => ['Laravel', 'Docker'],
            'Thura' => ['React', 'TypeScript'],
            'Thiri Aye' => ['Figma', 'UI/UX Design'],
            'Su Mon' => ['Figma', 'UI/UX Design'],
            'Ei Phyo' => ['Figma', 'UI/UX Design'],
            'Min Hein' => ['Scrum', 'Agile'],
            'Aye Thin' => ['Scrum', 'Agile'],
            'Phyu Phyu' => ['Agile'],
            'Zaw Lin' => ['Manual Testing', 'Automated Testing'],
            'May Thu' => ['Manual Testing', 'Automated Testing'],
        ];

        $empIds = DB::table('employees')->where('tenant_id', $this->tenantId)->pluck('id', 'name')->toArray();
        foreach ($employeeSkills as $empName => $skillsList) {
            foreach ($skillsList as $skillName) {
                if (!isset($skillIds[$skillName]) || !isset($empIds[$empName])) continue;
                DB::table('employee_skills')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $this->tenantId,
                    'employee_id' => $empIds[$empName],
                    'skill_id' => $skillIds[$skillName],
                    'proficiency' => ['beginner', 'intermediate', 'expert'][rand(0, 2)],
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }
        }
    }

    private function seedCompanySettings(): void
    {
        DB::table('company_settings')->updateOrInsert(
            ['tenant_id' => $this->tenantId],
            [
                'id' => 'singleton',
                'tenant_id' => $this->tenantId,
                'overhead_percentage' => 20.00,
                'buffer_percentage' => 10.00,
                'yearly_fixed_cost' => 360000000.00,
                'employer_tax_percentage' => 8.00,
                'benefits_percentage' => 12.00,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]
        );
    }

    private function seedGlobalOverheads(): void
    {
        $overheads = [
            ['category' => 'Office Rent', 'description' => 'Yangon office lease (Hledan Centre)', 'monthly_cost' => 15000000],
            ['category' => 'Internet & Telecom', 'description' => 'Fiber, phone, mobile packages', 'monthly_cost' => 2500000],
            ['category' => 'Software Licenses', 'description' => 'GitHub, Figma, Slack, AWS, JetBrains', 'monthly_cost' => 8000000],
            ['category' => 'Accounting & Legal', 'description' => 'Bookkeeping, audit, tax, legal', 'monthly_cost' => 5000000],
            ['category' => 'Marketing & Events', 'description' => 'Social ads, conferences, meetups', 'monthly_cost' => 3500000],
        ];

        foreach ($overheads as $oh) {
            DB::table('global_overheads')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'category' => $oh['category'],
                'description' => $oh['description'],
                'monthly_cost' => $oh['monthly_cost'],
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedDeals(): void
    {
        // 4 PROFITABLE deals, 2 NOT PROFITABLE deals
        $deals = [
            // === PROFITABLE (margin >= 20%) ===
            [
                'name' => 'GreenMart E-Commerce Platform',
                'client' => 'GreenMart Holdings',
                'status' => 'won',
                'win_probability' => 100,
                'client_budget' => 180000000,
                'timeline_months' => 8,
                'workload_hours' => 3200,
                'workload_description' => 'Full-stack e-commerce with inventory, KBZ Pay/Wave Money integration, mobile-responsive storefront. React + Node.js + PostgreSQL.',
                'target_margin' => 30,
                'base_labor_cost' => 96000000,
                'overhead_cost' => 19200000,
                'buffer_cost' => 9600000,
                'total_estimated_cost' => 124800000,
                'estimated_gross_profit' => 55200000,
                'estimated_value' => 180000000,
                'won_at' => '2026-02-15 00:00:00',
            ],
            [
                'name' => 'MedConnect Telehealth Portal',
                'client' => 'MedConnect Health Myanmar',
                'status' => 'contract',
                'win_probability' => 90,
                'client_budget' => 95000000,
                'timeline_months' => 5,
                'workload_hours' => 1600,
                'workload_description' => 'HIPAA-compliant telehealth with video consultation, appointment scheduling, EMR integration. React + Laravel.',
                'target_margin' => 26,
                'base_labor_cost' => 54000000,
                'overhead_cost' => 10800000,
                'buffer_cost' => 5400000,
                'total_estimated_cost' => 70200000,
                'estimated_gross_profit' => 24800000,
                'estimated_value' => 95000000,
            ],
            [
                'name' => 'Aya Bank Digital Dashboard',
                'client' => 'Aya Bank Digital Team',
                'status' => 'proposal',
                'win_probability' => 75,
                'client_budget' => 65000000,
                'timeline_months' => 3,
                'workload_hours' => 960,
                'workload_description' => 'Internal analytics dashboard redesign. Real-time portfolio tracking, role-based views, modern data viz.',
                'target_margin' => 36,
                'base_labor_cost' => 32000000,
                'overhead_cost' => 6400000,
                'buffer_cost' => 3200000,
                'total_estimated_cost' => 41600000,
                'estimated_gross_profit' => 23400000,
                'estimated_value' => 65000000,
            ],
            [
                'name' => 'Myanmar ERP System',
                'client' => 'Myanmar Enterprise Holdings',
                'status' => 'won',
                'win_probability' => 100,
                'client_budget' => 220000000,
                'timeline_months' => 10,
                'workload_hours' => 4000,
                'workload_description' => 'Enterprise ERP: inventory, HR, finance, supply chain. Multi-tenant, role-based access, Myanmar language support.',
                'target_margin' => 29,
                'base_labor_cost' => 120000000,
                'overhead_cost' => 24000000,
                'buffer_cost' => 12000000,
                'total_estimated_cost' => 156000000,
                'estimated_gross_profit' => 64000000,
                'estimated_value' => 220000000,
                'won_at' => '2026-04-10 00:00:00',
            ],

            // === NOT PROFITABLE (margin < 10% or negative) ===
            [
                'name' => 'TravelOK Booking Engine',
                'client' => 'TravelOK Myanmar',
                'status' => 'inquiry',
                'win_probability' => 50,
                'client_budget' => 80000000,
                'timeline_months' => 7,
                'workload_hours' => 2400,
                'workload_description' => 'Travel booking engine with flight/hotel search, dynamic pricing, multi-currency, affiliate portal. Client has tight budget.',
                'target_margin' => 8,
                'base_labor_cost' => 72000000,
                'overhead_cost' => 14400000,
                'buffer_cost' => 7200000,
                'total_estimated_cost' => 93600000,
                'estimated_gross_profit' => -13600000,
                'estimated_value' => 80000000,
            ],
            [
                'name' => 'EduNext LMS Platform',
                'client' => 'EduNext Academy Myanmar',
                'status' => 'lead',
                'win_probability' => 20,
                'client_budget' => 150000000,
                'timeline_months' => 12,
                'workload_hours' => 4800,
                'workload_description' => 'LMS with course authoring, live classes, progress tracking, certificates. Scope is massive for the budget.',
                'target_margin' => 5,
                'base_labor_cost' => 120000000,
                'overhead_cost' => 24000000,
                'buffer_cost' => 12000000,
                'total_estimated_cost' => 156000000,
                'estimated_gross_profit' => -6000000,
                'estimated_value' => 150000000,
            ],
        ];

        $this->dealIds = [];
        foreach ($deals as $deal) {
            $id = Str::uuid()->toString();
            $this->dealIds[$deal['name']] = $id;
            $wonAt = $deal['won_at'] ?? null;
            unset($deal['won_at']);

            DB::table('deals')->insert(array_merge($deal, [
                'id' => $id,
                'tenant_id' => $this->tenantId,
                'won_at' => $wonAt,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]));
        }

        $this->seedGhostRoles();
        $this->seedHardAssignments();
    }

    private function seedGhostRoles(): void
    {
        $ghostRoles = [
            // GreenMart — won (profitable)
            [$this->dealIds['GreenMart E-Commerce Platform'], 'backend', 2, 8, 4800000, 3000000, 5500000],
            [$this->dealIds['GreenMart E-Commerce Platform'], 'frontend', 2, 8, 4500000, 2800000, 5200000],
            [$this->dealIds['GreenMart E-Commerce Platform'], 'pm', 1, 8, 4000000, 3000000, 4800000],
            [$this->dealIds['GreenMart E-Commerce Platform'], 'qa', 1, 6, 3000000, 2000000, 4000000],
            [$this->dealIds['GreenMart E-Commerce Platform'], 'design', 1, 4, 3500000, 2500000, 4500000],

            // MedConnect — contract (profitable)
            [$this->dealIds['MedConnect Telehealth Portal'], 'backend', 2, 5, 4800000, 3000000, 5500000],
            [$this->dealIds['MedConnect Telehealth Portal'], 'frontend', 1, 5, 4500000, 2800000, 5200000],
            [$this->dealIds['MedConnect Telehealth Portal'], 'pm', 1, 5, 4000000, 3000000, 4800000],
            [$this->dealIds['MedConnect Telehealth Portal'], 'qa', 1, 4, 3000000, 2000000, 4000000],

            // Aya Bank — proposal (profitable)
            [$this->dealIds['Aya Bank Digital Dashboard'], 'frontend', 2, 3, 4500000, 2800000, 5200000],
            [$this->dealIds['Aya Bank Digital Dashboard'], 'backend', 1, 3, 4800000, 3000000, 5500000],
            [$this->dealIds['Aya Bank Digital Dashboard'], 'design', 1, 2, 3500000, 2500000, 4500000],

            // Myanmar ERP — won (profitable)
            [$this->dealIds['Myanmar ERP System'], 'backend', 3, 10, 5200000, 3500000, 6000000],
            [$this->dealIds['Myanmar ERP System'], 'frontend', 2, 10, 4800000, 3000000, 5500000],
            [$this->dealIds['Myanmar ERP System'], 'pm', 1, 10, 4500000, 3000000, 5500000],
            [$this->dealIds['Myanmar ERP System'], 'qa', 2, 8, 3500000, 2200000, 4200000],
            [$this->dealIds['Myanmar ERP System'], 'design', 1, 6, 3800000, 2800000, 4800000],

            // TravelOK — inquiry (NOT profitable)
            [$this->dealIds['TravelOK Booking Engine'], 'backend', 3, 7, 4800000, 3000000, 5500000],
            [$this->dealIds['TravelOK Booking Engine'], 'frontend', 2, 7, 4500000, 2800000, 5200000],
            [$this->dealIds['TravelOK Booking Engine'], 'pm', 1, 7, 4000000, 3000000, 4800000],
            [$this->dealIds['TravelOK Booking Engine'], 'qa', 1, 5, 3000000, 2000000, 4000000],

            // EduNext — lead (NOT profitable)
            [$this->dealIds['EduNext LMS Platform'], 'backend', 4, 12, 4800000, 3000000, 5500000],
            [$this->dealIds['EduNext LMS Platform'], 'frontend', 3, 12, 4500000, 2800000, 5200000],
            [$this->dealIds['EduNext LMS Platform'], 'pm', 1, 12, 4000000, 3000000, 4800000],
            [$this->dealIds['EduNext LMS Platform'], 'qa', 2, 10, 3000000, 2000000, 4000000],
            [$this->dealIds['EduNext LMS Platform'], 'design', 1, 6, 3500000, 2500000, 4500000],
        ];

        foreach ($ghostRoles as [$dealId, $roleType, $qty, $months, $salary, $minSalary, $maxSalary]) {
            DB::table('deal_ghost_roles')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'deal_id' => $dealId,
                'role_type' => $roleType,
                'quantity' => $qty,
                'months' => $months,
                'avg_monthly_salary' => $salary,
                'min_monthly_salary' => $minSalary,
                'max_monthly_salary' => $maxSalary,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedHardAssignments(): void
    {
        $employees = DB::table('employees')->where('tenant_id', $this->tenantId)->where('status', 'Active')->get();

        // GreenMart assignments
        foreach ([
            ['role' => 'backend', 'hours' => 1280],
            ['role' => 'backend', 'hours' => 1280],
            ['role' => 'frontend', 'hours' => 1280],
            ['role' => 'frontend', 'hours' => 640],
            ['role' => 'pm', 'hours' => 960],
            ['role' => 'qa', 'hours' => 640],
        ] as $assignment) {
            $emp = $employees->first(fn($e) => $e->capacity_role === $assignment['role']);
            if ($emp) {
                $employees = $employees->reject(fn($e) => $e->id === $emp->id)->values();
                DB::table('deal_hard_assignments')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $this->tenantId,
                    'deal_id' => $this->dealIds['GreenMart E-Commerce Platform'],
                    'employee_id' => $emp->id,
                    'allocated_hours' => $assignment['hours'],
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }
        }

        // Myanmar ERP assignments
        $employees = DB::table('employees')->where('tenant_id', $this->tenantId)->where('status', 'Active')->get();
        foreach ([
            ['role' => 'backend', 'hours' => 1600],
            ['role' => 'backend', 'hours' => 1200],
            ['role' => 'backend', 'hours' => 800],
            ['role' => 'frontend', 'hours' => 1200],
            ['role' => 'frontend', 'hours' => 800],
            ['role' => 'pm', 'hours' => 1200],
            ['role' => 'qa', 'hours' => 800],
            ['role' => 'qa', 'hours' => 400],
            ['role' => 'design', 'hours' => 600],
        ] as $assignment) {
            $emp = $employees->first(fn($e) => $e->capacity_role === $assignment['role']);
            if ($emp) {
                $employees = $employees->reject(fn($e) => $e->id === $emp->id)->values();
                DB::table('deal_hard_assignments')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $this->tenantId,
                    'deal_id' => $this->dealIds['Myanmar ERP System'],
                    'employee_id' => $emp->id,
                    'allocated_hours' => $assignment['hours'],
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }
        }
    }

    private function seedContractsAndProjects(): void
    {
        $now = $this->now;

        // GreenMart Contract + Project (Active)
        $contract1 = Str::uuid()->toString();
        DB::table('contracts')->insert([
            'id' => $contract1,
            'tenant_id' => $this->tenantId,
            'deal_id' => $this->dealIds['GreenMart E-Commerce Platform'],
            'contract_number' => 'CON-2026-001',
            'client' => 'GreenMart Holdings',
            'total_value' => 180000000,
            'revenue_recognized' => 60000000,
            'status' => 'Active',
            'start_date' => '2026-02-15',
            'end_date' => '2026-10-15',
            'notes' => 'Phase-based delivery. Monthly billing milestones. KBZ Pay accepted.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('projects')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'contract_id' => $contract1,
            'project_number' => 'PRJ-2026-001',
            'name' => 'GreenMart E-Commerce Platform',
            'client' => 'GreenMart Holdings',
            'budget_hours' => 3200,
            'consumed_hours' => 1280,
            'status' => 'On Track',
            'start_date' => '2026-02-15',
            'end_date' => '2026-10-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // MedConnect Contract + Project (Not Started)
        $contract2 = Str::uuid()->toString();
        DB::table('contracts')->insert([
            'id' => $contract2,
            'tenant_id' => $this->tenantId,
            'deal_id' => $this->dealIds['MedConnect Telehealth Portal'],
            'contract_number' => 'CON-2026-002',
            'client' => 'MedConnect Health Myanmar',
            'total_value' => 95000000,
            'revenue_recognized' => 0,
            'status' => 'Draft',
            'start_date' => '2026-05-01',
            'end_date' => '2026-10-01',
            'notes' => 'HIPAA compliance required. Kickoff pending legal review.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('projects')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'contract_id' => $contract2,
            'project_number' => 'PRJ-2026-002',
            'name' => 'MedConnect Telehealth Portal',
            'client' => 'MedConnect Health Myanmar',
            'budget_hours' => 1600,
            'consumed_hours' => 0,
            'status' => 'Not Started',
            'start_date' => '2026-05-01',
            'end_date' => '2026-10-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Myanmar ERP Contract + Project (Not Started)
        $contract3 = Str::uuid()->toString();
        DB::table('contracts')->insert([
            'id' => $contract3,
            'tenant_id' => $this->tenantId,
            'deal_id' => $this->dealIds['Myanmar ERP System'],
            'contract_number' => 'CON-2026-003',
            'client' => 'Myanmar Enterprise Holdings',
            'total_value' => 220000000,
            'revenue_recognized' => 0,
            'status' => 'Draft',
            'start_date' => '2026-04-10',
            'end_date' => '2027-02-10',
            'notes' => 'ERP modules: inventory, HR, finance, supply chain. Multi-tenant architecture.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('projects')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'contract_id' => $contract3,
            'project_number' => 'PRJ-2026-003',
            'name' => 'Myanmar ERP System',
            'client' => 'Myanmar Enterprise Holdings',
            'budget_hours' => 4000,
            'consumed_hours' => 0,
            'status' => 'Not Started',
            'start_date' => '2026-04-10',
            'end_date' => '2027-02-10',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedMilestones(): void
    {
        $contracts = DB::table('contracts')->where('tenant_id', $this->tenantId)->pluck('id', 'contract_number')->toArray();

        $milestones = [
            ['contract_id' => $contracts['CON-2026-001'], 'name' => 'Phase 1: Discovery & UX Design', 'due_date' => '2026-03-15', 'amount' => 30000000, 'status' => 'Completed'],
            ['contract_id' => $contracts['CON-2026-001'], 'name' => 'Phase 2: Core Platform MVP', 'due_date' => '2026-05-15', 'amount' => 50000000, 'status' => 'In Progress'],
            ['contract_id' => $contracts['CON-2026-001'], 'name' => 'Phase 3: Payment & Integration', 'due_date' => '2026-08-01', 'amount' => 60000000, 'status' => 'Pending'],
            ['contract_id' => $contracts['CON-2026-001'], 'name' => 'Phase 4: QA, Launch & Handoff', 'due_date' => '2026-10-01', 'amount' => 40000000, 'status' => 'Pending'],

            ['contract_id' => $contracts['CON-2026-002'], 'name' => 'Kickoff & Architecture', 'due_date' => '2026-06-01', 'amount' => 19000000, 'status' => 'Pending'],
            ['contract_id' => $contracts['CON-2026-002'], 'name' => 'Core Portal Development', 'due_date' => '2026-08-15', 'amount' => 47500000, 'status' => 'Pending'],
            ['contract_id' => $contracts['CON-2026-002'], 'name' => 'Testing & Go-Live', 'due_date' => '2026-10-01', 'amount' => 28500000, 'status' => 'Pending'],

            ['contract_id' => $contracts['CON-2026-003'], 'name' => 'Requirements & Architecture', 'due_date' => '2026-05-15', 'amount' => 35000000, 'status' => 'Pending'],
            ['contract_id' => $contracts['CON-2026-003'], 'name' => 'Core ERP Modules', 'due_date' => '2026-09-15', 'amount' => 90000000, 'status' => 'Pending'],
            ['contract_id' => $contracts['CON-2026-003'], 'name' => 'Integration & Deployment', 'due_date' => '2026-12-15', 'amount' => 55000000, 'status' => 'Pending'],
            ['contract_id' => $contracts['CON-2026-003'], 'name' => 'Training & Handoff', 'due_date' => '2027-01-15', 'amount' => 40000000, 'status' => 'Pending'],
        ];

        foreach ($milestones as $ms) {
            DB::table('milestones')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'contract_id' => $ms['contract_id'],
                'name' => $ms['name'],
                'due_date' => $ms['due_date'],
                'amount' => $ms['amount'],
                'status' => $ms['status'],
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedInvoices(): void
    {
        $contracts = DB::table('contracts')->where('tenant_id', $this->tenantId)->get()->keyBy('contract_number');

        $invoices = [
            [
                'contract_id' => $contracts['CON-2026-001']->id,
                'invoice_number' => 'INV-2026-001',
                'amount' => 30000000,
                'tax' => 0,
                'status' => 'Paid',
                'issue_date' => '2026-03-15',
                'due_date' => '2026-03-31',
                'paid_at' => '2026-03-28 00:00:00',
                'notes' => 'Phase 1 milestone — paid via KBZ Bank transfer',
            ],
            [
                'contract_id' => $contracts['CON-2026-001']->id,
                'invoice_number' => 'INV-2026-002',
                'amount' => 50000000,
                'tax' => 0,
                'status' => 'Pending',
                'issue_date' => '2026-04-15',
                'due_date' => '2026-05-31',
                'paid_at' => null,
                'notes' => 'Phase 2 milestone — awaiting client approval',
            ],
            [
                'contract_id' => $contracts['CON-2026-002']->id,
                'invoice_number' => 'INV-2026-003',
                'amount' => 19000000,
                'tax' => 0,
                'status' => 'Pending',
                'issue_date' => '2026-05-01',
                'due_date' => '2026-06-15',
                'paid_at' => null,
                'notes' => 'Kickoff deposit — 20% upfront',
            ],
        ];

        foreach ($invoices as $inv) {
            DB::table('invoices')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'contract_id' => $inv['contract_id'],
                'invoice_number' => $inv['invoice_number'],
                'amount' => $inv['amount'],
                'tax' => $inv['tax'],
                'status' => $inv['status'],
                'issue_date' => $inv['issue_date'],
                'due_date' => $inv['due_date'],
                'paid_at' => $inv['paid_at'],
                'notes' => $inv['notes'],
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedTimeEntries(): void
    {
        $projects = DB::table('projects')->where('tenant_id', $this->tenantId)->pluck('id', 'name')->toArray();
        $employees = DB::table('employees')->where('tenant_id', $this->tenantId)->where('status', 'Active')->pluck('id', 'name')->toArray();

        $entries = [
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Aung Khant'], 'task' => 'Backend API architecture', 'date' => '2026-04-01', 'hours' => 8, 'status' => 'Approved'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Thiha Soe'], 'task' => 'Frontend component library', 'date' => '2026-04-01', 'hours' => 8, 'status' => 'Approved'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Myo Aung'], 'task' => 'Payment gateway integration', 'date' => '2026-04-02', 'hours' => 6, 'status' => 'Approved'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Wai Phyo'], 'task' => 'Product listing page', 'date' => '2026-04-02', 'hours' => 7, 'status' => 'Approved'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Khin Thu'], 'task' => 'Cart & checkout flow', 'date' => '2026-04-03', 'hours' => 8, 'status' => 'Approved'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Zaw Lin'], 'task' => 'API endpoint testing', 'date' => '2026-04-03', 'hours' => 5, 'status' => 'Approved'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Aung Khant'], 'task' => 'Order management API', 'date' => '2026-04-07', 'hours' => 8, 'status' => 'Draft'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Thiha Soe'], 'task' => 'Checkout UI polish', 'date' => '2026-04-07', 'hours' => 6, 'status' => 'Draft'],
            ['project_id' => $projects['GreenMart E-Commerce Platform'], 'employee_id' => $employees['Nyein Chan'], 'task' => 'Mobile responsive fixes', 'date' => '2026-04-08', 'hours' => 7, 'status' => 'Pending'],
        ];

        foreach ($entries as $entry) {
            DB::table('time_entries')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'project_id' => $entry['project_id'],
                'employee_id' => $entry['employee_id'],
                'task' => $entry['task'],
                'date' => $entry['date'],
                'hours' => $entry['hours'],
                'billable' => true,
                'status' => $entry['status'],
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedProjectTeamAssignments(): void
    {
        $projects = DB::table('projects')->where('tenant_id', $this->tenantId)->pluck('id', 'name')->toArray();
        $employees = DB::table('employees')->where('tenant_id', $this->tenantId)->where('status', 'Active')->get();

        $greenMartTeam = $employees->filter(fn($e) => in_array($e->capacity_role, ['backend', 'frontend', 'pm', 'qa']))->take(6);
        foreach ($greenMartTeam as $emp) {
            DB::table('project_team_assignments')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'project_id' => $projects['GreenMart E-Commerce Platform'],
                'employee_id' => $emp->id,
                'allocated_hours' => 160,
                'assignment_source' => 'manual',
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }
}
