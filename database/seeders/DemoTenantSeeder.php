<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoTenantSeeder extends Seeder
{
    private string $tenantId;

    public function run(): void
    {
        $this->tenantId = 'aa24b68f-9de2-4621-b404-fb3edd318ee6';

        $this->seedDepartments();
        $this->seedRoles();
        $this->seedEmployees();
        $this->seedCompanySettings();
        $this->seedGlobalOverheads();
        $this->seedDeals();
        $this->seedContractsAndProjects();
        $this->seedMilestones();
        $this->seedInvoices();
        $this->seedTimeEntries();
    }

    private function seedDepartments(): void
    {
        if (DB::table('departments')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $departments = [
            ['name' => 'Engineering', 'manager' => 'Aung Khant', 'headcount' => 6],
            ['name' => 'Design', 'manager' => 'Thiri Aye', 'headcount' => 2],
            ['name' => 'Project Management', 'manager' => 'Min Hein', 'headcount' => 2],
            ['name' => 'Quality Assurance', 'manager' => 'Zaw Lin', 'headcount' => 2],
            ['name' => 'Business Development', 'manager' => 'Hnin Wai', 'headcount' => 2],
        ];

        foreach ($departments as $dept) {
            DB::table('departments')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'name' => $dept['name'],
                'manager' => $dept['manager'],
                'headcount' => $dept['headcount'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedRoles(): void
    {
        if (DB::table('roles')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $engineeringId = DB::table('departments')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'Engineering')
            ->value('id');

        $designId = DB::table('departments')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'Design')
            ->value('id');

        $pmId = DB::table('departments')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'Project Management')
            ->value('id');

        $qaId = DB::table('departments')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'Quality Assurance')
            ->value('id');

        $bdId = DB::table('departments')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'Business Development')
            ->value('id');

        $roles = [
            ['title' => 'Senior Frontend Engineer', 'department_id' => $engineeringId, 'department' => 'Engineering', 'rate' => 85],
            ['title' => 'Senior Backend Engineer', 'department_id' => $engineeringId, 'department' => 'Engineering', 'rate' => 95],
            ['title' => 'Full Stack Engineer', 'department_id' => $engineeringId, 'department' => 'Engineering', 'rate' => 90],
            ['title' => 'Junior Frontend Engineer', 'department_id' => $engineeringId, 'department' => 'Engineering', 'rate' => 55],
            ['title' => 'Junior Backend Engineer', 'department_id' => $engineeringId, 'department' => 'Engineering', 'rate' => 60],
            ['title' => 'UI/UX Designer', 'department_id' => $designId, 'department' => 'Design', 'rate' => 75],
            ['title' => 'Visual Designer', 'department_id' => $designId, 'department' => 'Design', 'rate' => 65],
            ['title' => 'Project Manager', 'department_id' => $pmId, 'department' => 'Project Management', 'rate' => 80],
            ['title' => 'Scrum Master', 'department_id' => $pmId, 'department' => 'Project Management', 'rate' => 70],
            ['title' => 'QA Engineer', 'department_id' => $qaId, 'department' => 'Quality Assurance', 'rate' => 60],
            ['title' => 'QA Lead', 'department_id' => $qaId, 'department' => 'Quality Assurance', 'rate' => 75],
            ['title' => 'Sales Executive', 'department_id' => $bdId, 'department' => 'Business Development', 'rate' => 70],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'department_id' => $role['department_id'],
                'title' => $role['title'],
                'department' => $role['department'],
                'rate' => $role['rate'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedEmployees(): void
    {
        if (DB::table('employees')->where('tenant_id', $this->tenantId)->count() > 1) {
            return;
        }
        $now = now()->toDateTimeString();

        $roles = DB::table('roles')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->keyBy('title');

        $departments = DB::table('departments')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->keyBy('name');

        $employees = [
            ['name' => 'Aung Khant', 'role' => 'Senior Backend Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 5500, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Thiha Soe', 'role' => 'Senior Frontend Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 5200, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Min Hein', 'role' => 'Project Manager', 'capacity_role' => 'pm', 'dept' => 'Project Management', 'salary' => 4800, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Thiri Aye', 'role' => 'UI/UX Designer', 'capacity_role' => 'design', 'dept' => 'Design', 'salary' => 3800, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Zaw Lin', 'role' => 'QA Engineer', 'capacity_role' => 'qa', 'dept' => 'Quality Assurance', 'salary' => 3200, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Hnin Wai', 'role' => 'Sales Executive', 'capacity_role' => null, 'dept' => 'Business Development', 'salary' => 4000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Myo Aung', 'role' => 'Full Stack Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 4800, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Wai Phyo', 'role' => 'Junior Frontend Engineer', 'capacity_role' => 'frontend', 'dept' => 'Engineering', 'salary' => 2800, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Khin Thu', 'role' => 'Junior Backend Engineer', 'capacity_role' => 'backend', 'dept' => 'Engineering', 'salary' => 3000, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Su Mon', 'role' => 'QA Lead', 'capacity_role' => 'qa', 'dept' => 'Quality Assurance', 'salary' => 3800, 'hours' => 160, 'status' => 'On Leave'],
            ['name' => 'Nyein Chan', 'role' => 'Visual Designer', 'capacity_role' => 'design', 'dept' => 'Design', 'salary' => 3400, 'hours' => 160, 'status' => 'Active'],
            ['name' => 'Aye Thin', 'role' => 'Scrum Master', 'capacity_role' => 'pm', 'dept' => 'Project Management', 'salary' => 3600, 'hours' => 160, 'status' => 'Active'],
        ];

        foreach ($employees as $emp) {
            $role = $roles[$emp['role']] ?? null;
            $dept = $departments[$emp['dept']] ?? null;
            $costPerHour = $emp['hours'] > 0 ? round($emp['salary'] / $emp['hours'], 4) : 0;

            DB::table('employees')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'department_id' => $dept?->id,
                'job_role_id' => $role?->id,
                'name' => $emp['name'],
                'role' => $emp['role'],
                'role_name' => $emp['role'],
                'capacity_role' => $emp['capacity_role'],
                'monthly_salary' => $emp['salary'],
                'workable_hours' => $emp['hours'],
                'cost_per_hour' => $costPerHour,
                'status' => $emp['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
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
                'yearly_fixed_cost' => 120000.00,
                'employer_tax_percentage' => 8.00,
                'benefits_percentage' => 12.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function seedGlobalOverheads(): void
    {
        if (DB::table('global_overheads')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $overheads = [
            ['category' => 'Office Rent', 'description' => 'Monthly office space lease in Yangon downtown', 'monthly_cost' => 4500],
            ['category' => 'Internet & Telecom', 'description' => 'Fiber internet, phone lines, and VPN services', 'monthly_cost' => 800],
            ['category' => 'Software Licenses', 'description' => 'GitHub, Figma, Slack, AWS, and productivity tools', 'monthly_cost' => 2200],
            ['category' => 'Accounting & Legal', 'description' => 'Monthly bookkeeping, audit prep, and legal retainer', 'monthly_cost' => 1500],
            ['category' => 'Marketing & Events', 'description' => 'Social media, conference tickets, and branding', 'monthly_cost' => 1000],
        ];

        foreach ($overheads as $oh) {
            DB::table('global_overheads')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'category' => $oh['category'],
                'description' => $oh['description'],
                'monthly_cost' => $oh['monthly_cost'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedDeals(): void
    {
        if (DB::table('deals')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $deals = [
            [
                'name' => 'GreenMart E-Commerce Platform',
                'client' => 'GreenMart Holdings',
                'status' => 'won',
                'win_probability' => 100,
                'client_budget' => 180000,
                'timeline_months' => 8,
                'workload_hours' => 3200,
                'workload_description' => 'Full-stack e-commerce platform with inventory management, payment gateway integration, and mobile-responsive storefront. Tech stack: React, Node.js, PostgreSQL, Stripe.',
                'target_margin' => 30,
                'base_labor_cost' => 96000,
                'overhead_cost' => 19200,
                'buffer_cost' => 9600,
                'total_estimated_cost' => 124800,
                'estimated_gross_profit' => 55200,
                'estimated_value' => 180000,
                'won_at' => '2026-02-15 00:00:00',
            ],
            [
                'name' => 'MedConnect Telehealth Portal',
                'client' => 'MedConnect Health',
                'status' => 'contract',
                'win_probability' => 90,
                'client_budget' => 95000,
                'timeline_months' => 5,
                'workload_hours' => 1600,
                'workload_description' => 'HIPAA-compliant telehealth portal with video consultation, appointment scheduling, and EHR integration. React frontend, Laravel API backend.',
                'target_margin' => 30,
                'base_labor_cost' => 54000,
                'overhead_cost' => 10800,
                'buffer_cost' => 5400,
                'total_estimated_cost' => 70200,
                'estimated_gross_profit' => 24800,
                'estimated_value' => 95000,
            ],
            [
                'name' => 'Fintech Dashboard Redesign',
                'client' => 'Aya Bank Digital',
                'status' => 'proposal',
                'win_probability' => 75,
                'client_budget' => 65000,
                'timeline_months' => 3,
                'workload_hours' => 960,
                'workload_description' => 'Redesign and rebuild the internal analytics dashboard for Aya Bank. Modern data visualizations, real-time portfolio tracking, and role-based views.',
                'target_margin' => 30,
                'base_labor_cost' => 32000,
                'overhead_cost' => 6400,
                'buffer_cost' => 3200,
                'total_estimated_cost' => 41600,
                'estimated_gross_profit' => 23400,
                'estimated_value' => 65000,
            ],
            [
                'name' => 'TravelOK Booking Engine',
                'client' => 'TravelOK Group',
                'status' => 'inquiry',
                'win_probability' => 50,
                'client_budget' => 120000,
                'timeline_months' => 7,
                'workload_hours' => 2400,
                'workload_description' => 'Scalable travel booking engine with flight/hotel search, dynamic pricing, multi-currency support, and affiliate management portal.',
                'target_margin' => 30,
                'base_labor_cost' => 72000,
                'overhead_cost' => 14400,
                'buffer_cost' => 7200,
                'total_estimated_cost' => 93600,
                'estimated_gross_profit' => 26400,
                'estimated_value' => 120000,
            ],
            [
                'name' => 'EduNext LMS Platform',
                'client' => 'EduNext Academy',
                'status' => 'lead',
                'win_probability' => 20,
                'client_budget' => 200000,
                'timeline_months' => 12,
                'workload_hours' => 4800,
                'workload_description' => 'Comprehensive learning management system with course authoring, live classes, progress tracking, certificates, and payment integration.',
                'target_margin' => 30,
                'base_labor_cost' => 144000,
                'overhead_cost' => 28800,
                'buffer_cost' => 14400,
                'total_estimated_cost' => 182400,
                'estimated_gross_profit' => 17600,
                'estimated_value' => 200000,
            ],
            [
                'name' => 'SmartFactory IoT Dashboard',
                'client' => 'Myanmar Industrial Corp',
                'status' => 'proposal',
                'win_probability' => 60,
                'client_budget' => 75000,
                'timeline_months' => 4,
                'workload_hours' => 1280,
                'workload_description' => 'Real-time IoT dashboard for factory monitoring — machine status, temperature sensors, production analytics, and alert system.',
                'target_margin' => 30,
                'base_labor_cost' => 44000,
                'overhead_cost' => 8800,
                'buffer_cost' => 4400,
                'total_estimated_cost' => 57200,
                'estimated_gross_profit' => 17800,
                'estimated_value' => 75000,
            ],
            [
                'name' => 'Wayne Enterprise ERP System',
                'client' => 'Wayne Enterprise Holdings',
                'status' => 'won',
                'win_probability' => 100,
                'client_budget' => 220000,
                'timeline_months' => 10,
                'workload_hours' => 4000,
                'workload_description' => 'Enterprise ERP system with inventory, HR, finance, and supply chain modules. Multi-tenant architecture with role-based access control.',
                'target_margin' => 32,
                'base_labor_cost' => 120000,
                'overhead_cost' => 24000,
                'buffer_cost' => 12000,
                'total_estimated_cost' => 156000,
                'estimated_gross_profit' => 64000,
                'estimated_value' => 220000,
                'won_at' => '2026-04-10 00:00:00',
            ],
            [
                'name' => 'Failed Startup App',
                'client' => 'QuickBite Delivery',
                'status' => 'lost',
                'win_probability' => 0,
                'client_budget' => 45000,
                'timeline_months' => 3,
                'workload_hours' => 720,
                'workload_description' => 'Food delivery mobile app with real-time tracking, Stripe payments, and restaurant dashboard.',
                'target_margin' => 30,
                'base_labor_cost' => 24000,
                'overhead_cost' => 4800,
                'buffer_cost' => 2400,
                'total_estimated_cost' => 31200,
                'estimated_gross_profit' => 13800,
                'estimated_value' => 45000,
            ],
        ];

        $dealIds = [];
        foreach ($deals as $deal) {
            $id = Str::uuid()->toString();
            $dealIds[$deal['name']] = $id;
            $wonAt = $deal['won_at'] ?? null;
            unset($deal['won_at']);

            DB::table('deals')->insert(array_merge($deal, [
                'id' => $id,
                'tenant_id' => $this->tenantId,
                'won_at' => $wonAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // Ghost roles for deals
        $this->seedGhostRoles($dealIds);

        // Hard assignments for the won deal (GreenMart)
        $this->seedHardAssignments($dealIds);
    }

    private function seedGhostRoles(array $dealIds): void
    {
        $now = now()->toDateTimeString();

        $ghostRoles = [
            // GreenMart — won
            [$dealIds['GreenMart E-Commerce Platform'], 'backend', 2, 8, 4800, 3000, 5500],
            [$dealIds['GreenMart E-Commerce Platform'], 'frontend', 2, 8, 4500, 2800, 5200],
            [$dealIds['GreenMart E-Commerce Platform'], 'pm', 1, 8, 4000, 3000, 4800],
            [$dealIds['GreenMart E-Commerce Platform'], 'qa', 1, 6, 3000, 2000, 4000],
            [$dealIds['GreenMart E-Commerce Platform'], 'design', 1, 4, 3500, 2500, 4500],

            // MedConnect — contract
            [$dealIds['MedConnect Telehealth Portal'], 'backend', 2, 5, 4800, 3000, 5500],
            [$dealIds['MedConnect Telehealth Portal'], 'frontend', 1, 5, 4500, 2800, 5200],
            [$dealIds['MedConnect Telehealth Portal'], 'pm', 1, 5, 4000, 3000, 4800],
            [$dealIds['MedConnect Telehealth Portal'], 'qa', 1, 4, 3000, 2000, 4000],

            // Fintech Dashboard — proposal
            [$dealIds['Fintech Dashboard Redesign'], 'frontend', 2, 3, 4500, 2800, 5200],
            [$dealIds['Fintech Dashboard Redesign'], 'backend', 1, 3, 4800, 3000, 5500],
            [$dealIds['Fintech Dashboard Redesign'], 'design', 1, 2, 3500, 2500, 4500],

            // TravelOK — inquiry
            [$dealIds['TravelOK Booking Engine'], 'backend', 3, 7, 4800, 3000, 5500],
            [$dealIds['TravelOK Booking Engine'], 'frontend', 2, 7, 4500, 2800, 5200],
            [$dealIds['TravelOK Booking Engine'], 'pm', 1, 7, 4000, 3000, 4800],
            [$dealIds['TravelOK Booking Engine'], 'qa', 1, 5, 3000, 2000, 4000],

            // EduNext — lead
            [$dealIds['EduNext LMS Platform'], 'backend', 4, 12, 4800, 3000, 5500],
            [$dealIds['EduNext LMS Platform'], 'frontend', 3, 12, 4500, 2800, 5200],
            [$dealIds['EduNext LMS Platform'], 'pm', 1, 12, 4000, 3000, 4800],
            [$dealIds['EduNext LMS Platform'], 'qa', 2, 10, 3000, 2000, 4000],
            [$dealIds['EduNext LMS Platform'], 'design', 1, 6, 3500, 2500, 4500],

            // SmartFactory — proposal
            [$dealIds['SmartFactory IoT Dashboard'], 'backend', 2, 4, 4800, 3000, 5500],
            [$dealIds['SmartFactory IoT Dashboard'], 'frontend', 1, 4, 4500, 2800, 5200],
            [$dealIds['SmartFactory IoT Dashboard'], 'pm', 1, 4, 4000, 3000, 4800],

            // Wayne Enterprise — won
            [$dealIds['Wayne Enterprise ERP System'], 'backend', 3, 10, 5200, 3500, 6000],
            [$dealIds['Wayne Enterprise ERP System'], 'frontend', 2, 10, 4800, 3000, 5500],
            [$dealIds['Wayne Enterprise ERP System'], 'pm', 1, 10, 4500, 3000, 5500],
            [$dealIds['Wayne Enterprise ERP System'], 'qa', 2, 8, 3500, 2200, 4200],
            [$dealIds['Wayne Enterprise ERP System'], 'design', 1, 6, 3800, 2800, 4800],

            // Failed Startup — lost
            [$dealIds['Failed Startup App'], 'backend', 1, 3, 4800, 3000, 5500],
            [$dealIds['Failed Startup App'], 'frontend', 1, 3, 4500, 2800, 5200],
            [$dealIds['Failed Startup App'], 'design', 1, 2, 3500, 2500, 4500],
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
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedHardAssignments(array $dealIds): void
    {
        $now = now()->toDateTimeString();

        $employees = DB::table('employees')
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'Active')
            ->get();

        // Assign real employees to the won deal (GreenMart)
        $assignments = [
            ['role' => 'backend', 'hours' => 1280],
            ['role' => 'backend', 'hours' => 1280],
            ['role' => 'frontend', 'hours' => 1280],
            ['role' => 'frontend', 'hours' => 640],
            ['role' => 'pm', 'hours' => 960],
            ['role' => 'qa', 'hours' => 640],
        ];

        $assigned = [];
        foreach ($assignments as $i => $assignment) {
            $emp = $employees->first(function ($e) use ($assignment, $assigned) {
                return $e->capacity_role === $assignment['role'] && ! in_array($e->id, $assigned);
            });

            if ($emp) {
                $assigned[] = $emp->id;
                DB::table('deal_hard_assignments')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $this->tenantId,
                    'deal_id' => $dealIds['GreenMart E-Commerce Platform'],
                    'employee_id' => $emp->id,
                    'allocated_hours' => $assignment['hours'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Assign real employees to the second won deal (Wayne Enterprise)
        $wayneAssignments = [
            ['role' => 'backend', 'hours' => 1600],
            ['role' => 'backend', 'hours' => 1200],
            ['role' => 'backend', 'hours' => 800],
            ['role' => 'frontend', 'hours' => 1200],
            ['role' => 'frontend', 'hours' => 800],
            ['role' => 'pm', 'hours' => 1200],
            ['role' => 'qa', 'hours' => 800],
            ['role' => 'qa', 'hours' => 400],
            ['role' => 'design', 'hours' => 600],
        ];

        $assignedWayne = [];
        foreach ($wayneAssignments as $assignment) {
            $emp = $employees->first(function ($e) use ($assignment, $assignedWayne) {
                return $e->capacity_role === $assignment['role'] && ! in_array($e->id, $assignedWayne);
            });

            if ($emp) {
                $assignedWayne[] = $emp->id;
                DB::table('deal_hard_assignments')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $this->tenantId,
                    'deal_id' => $dealIds['Wayne Enterprise ERP System'],
                    'employee_id' => $emp->id,
                    'allocated_hours' => $assignment['hours'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedContractsAndProjects(): void
    {
        if (DB::table('contracts')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $wonDealId = DB::table('deals')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'GreenMart E-Commerce Platform')
            ->value('id');

        // Contract for the won deal
        $contractId = Str::uuid()->toString();
        DB::table('contracts')->insert([
            'id' => $contractId,
            'tenant_id' => $this->tenantId,
            'deal_id' => $wonDealId,
            'contract_number' => 'CON-0001',
            'client' => 'GreenMart Holdings',
            'total_value' => 180000,
            'revenue_recognized' => 60000,
            'status' => 'Active',
            'start_date' => '2026-02-15',
            'end_date' => '2026-10-15',
            'notes' => 'Phase 1 & 2 delivery. Monthly billing milestones.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Project for the won deal
        DB::table('projects')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'contract_id' => $contractId,
            'project_number' => 'PRJ-101',
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

        // Third contract — for the second won deal (Wayne Enterprise)
        $wayneDealId = DB::table('deals')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'Wayne Enterprise ERP System')
            ->value('id');

        $contract3Id = Str::uuid()->toString();
        DB::table('contracts')->insert([
            'id' => $contract3Id,
            'tenant_id' => $this->tenantId,
            'deal_id' => $wayneDealId,
            'client' => 'Wayne Enterprise Holdings',
            'contract_number' => 'CON-0003',
            'total_value' => 220000,
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
            'contract_id' => $contract3Id,
            'name' => 'Wayne Enterprise ERP System',
            'client' => 'Wayne Enterprise Holdings',
            'project_number' => 'PRJ-103',
            'budget_hours' => 4000,
            'consumed_hours' => 0,
            'status' => 'Not Started',
            'start_date' => '2026-04-10',
            'end_date' => '2027-02-10',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Fourth contract — for a deal in "contract" stage
        $contractDealId = DB::table('deals')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'MedConnect Telehealth Portal')
            ->value('id');

        // Note: MedConnect deal has status=contract but hasn't been "won" via stored proc,
        // so we won't auto-create a contract/project for it here —
        // because the win_deal() stored proc would normally create these.
        // Instead, simulate it manually:
        $contract2Id = Str::uuid()->toString();
        DB::table('contracts')->insert([
            'id' => $contract2Id,
            'tenant_id' => $this->tenantId,
            'deal_id' => $contractDealId,
            'contract_number' => 'CON-0002',
            'client' => 'MedConnect Health',
            'total_value' => 95000,
            'revenue_recognized' => 0,
            'status' => 'Draft',
            'start_date' => '2026-05-01',
            'end_date' => '2026-10-01',
            'notes' => 'Contract signed but not yet invoiced. Kickoff pending.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('projects')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'contract_id' => $contract2Id,
            'project_number' => 'PRJ-102',
            'name' => 'MedConnect Telehealth Portal',
            'client' => 'MedConnect Health',
            'budget_hours' => 1600,
            'consumed_hours' => 0,
            'status' => 'Not Started',
            'start_date' => '2026-05-01',
            'end_date' => '2026-10-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedMilestones(): void
    {
        if (DB::table('milestones')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $greenmartContractId = DB::table('contracts')
            ->where('tenant_id', $this->tenantId)
            ->where('client', 'GreenMart Holdings')
            ->value('id');

        $medContractId = DB::table('contracts')
            ->where('tenant_id', $this->tenantId)
            ->where('client', 'MedConnect Health')
            ->value('id');

        $milestones = [
            // GreenMart milestones
            ['contract_id' => $greenmartContractId, 'name' => 'Phase 1: Discovery & UX Design', 'due_date' => '2026-03-15', 'amount' => 30000, 'status' => 'Completed'],
            ['contract_id' => $greenmartContractId, 'name' => 'Phase 2: Core Platform MVP', 'due_date' => '2026-05-15', 'amount' => 50000, 'status' => 'In Progress'],
            ['contract_id' => $greenmartContractId, 'name' => 'Phase 3: Payment & Integration', 'due_date' => '2026-08-01', 'amount' => 60000, 'status' => 'Pending'],
            ['contract_id' => $greenmartContractId, 'name' => 'Phase 4: QA, Launch & Handoff', 'due_date' => '2026-10-01', 'amount' => 40000, 'status' => 'Pending'],

            // MedConnect milestones
            ['contract_id' => $medContractId, 'name' => 'Kickoff & Architecture', 'due_date' => '2026-06-01', 'amount' => 19000, 'status' => 'Pending'],
            ['contract_id' => $medContractId, 'name' => 'Core Portal Development', 'due_date' => '2026-08-15', 'amount' => 47500, 'status' => 'Pending'],
            ['contract_id' => $medContractId, 'name' => 'Testing & Go-Live', 'due_date' => '2026-10-01', 'amount' => 28500, 'status' => 'Pending'],
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
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedInvoices(): void
    {
        if (DB::table('invoices')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $greenmartContractId = DB::table('contracts')
            ->where('tenant_id', $this->tenantId)
            ->where('client', 'GreenMart Holdings')
            ->value('id');

        // Phase 1 milestone
        $phase1MilestoneId = DB::table('milestones')
            ->where('contract_id', $greenmartContractId)
            ->where('name', 'Phase 1: Discovery & UX Design')
            ->value('id');

        $invoices = [
            [
                'contract_id' => $greenmartContractId,
                'milestone_id' => $phase1MilestoneId,
                'invoice_number' => 'INV-1042',
                'amount' => 30000,
                'tax' => 2400,
                'status' => 'Paid',
                'issue_date' => '2026-02-20',
                'due_date' => '2026-03-20',
                'paid_at' => '2026-03-18 10:00:00',
                'notes' => 'Phase 1 completion invoice',
            ],
            [
                'contract_id' => $greenmartContractId,
                'milestone_id' => null,
                'invoice_number' => 'INV-1043',
                'amount' => 25000,
                'tax' => 2000,
                'status' => 'Paid',
                'issue_date' => '2026-03-20',
                'due_date' => '2026-04-20',
                'paid_at' => '2026-04-15 14:30:00',
                'notes' => 'Interim billing — Phase 2 partial progress',
            ],
            [
                'contract_id' => $greenmartContractId,
                'milestone_id' => null,
                'invoice_number' => 'INV-1044',
                'amount' => 5000,
                'tax' => 400,
                'status' => 'Pending',
                'issue_date' => '2026-05-01',
                'due_date' => '2026-06-01',
                'paid_at' => null,
                'notes' => 'May retainer — Phase 2 ongoing',
            ],
            [
                'contract_id' => $greenmartContractId,
                'milestone_id' => null,
                'invoice_number' => 'INV-1045',
                'amount' => 5000,
                'tax' => 400,
                'status' => 'Paid',
                'issue_date' => '2026-04-15',
                'due_date' => '2026-05-15',
                'paid_at' => '2026-04-28 09:00:00',
                'notes' => 'April retainer — paid',
            ],
            [
                'contract_id' => $greenmartContractId,
                'milestone_id' => null,
                'invoice_number' => 'INV-1046',
                'amount' => 15000,
                'tax' => 1200,
                'status' => 'Paid',
                'issue_date' => '2026-04-28',
                'due_date' => '2026-05-28',
                'paid_at' => '2026-05-05 11:30:00',
                'notes' => 'Phase 2 milestone advance',
            ],
        ];

        $invoiceNumber = 1042;
        foreach ($invoices as $inv) {
            DB::table('invoices')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'contract_id' => $inv['contract_id'],
                'milestone_id' => $inv['milestone_id'],
'invoice_number' => $inv['invoice_number'] ?? 'INV-'.str_pad((string) $invoiceNumber++, 4, '0', STR_PAD_LEFT),
                'issue_date' => $inv['issue_date'],
                'due_date' => $inv['due_date'],
                'amount' => $inv['amount'],
                'tax' => $inv['tax'],
                'status' => $inv['status'],
                'paid_at' => $inv['paid_at'],
                'notes' => $inv['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedTimeEntries(): void
    {
        if (DB::table('time_entries')->where('tenant_id', $this->tenantId)->exists()) {
            return;
        }
        $now = now()->toDateTimeString();

        $greenmartProjectId = DB::table('projects')
            ->where('tenant_id', $this->tenantId)
            ->where('name', 'GreenMart E-Commerce Platform')
            ->value('id');

        $employees = DB::table('employees')
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'Active')
            ->get();

        $adminUserId = DB::table('users')
            ->where('email', 'admin@anka.dev')
            ->value('id');

        $entries = [
            ['emp_role' => 'backend', 'task' => 'API architecture & user auth module', 'date' => '2026-04-28', 'hours' => 8, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'backend', 'task' => 'Product catalog API endpoints', 'date' => '2026-04-29', 'hours' => 7.5, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'backend', 'task' => 'Payment gateway integration (Stripe)', 'date' => '2026-04-30', 'hours' => 6, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'frontend', 'task' => 'Storefront React component library', 'date' => '2026-04-28', 'hours' => 8, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'frontend', 'task' => 'Product listing & search UX', 'date' => '2026-04-29', 'hours' => 7, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'frontend', 'task' => 'Cart & checkout flow implementation', 'date' => '2026-04-30', 'hours' => 8, 'billable' => true, 'status' => 'Draft'],
            ['emp_role' => 'frontend', 'task' => 'Mobile responsive layout fixes', 'date' => '2026-05-01', 'hours' => 4, 'billable' => true, 'status' => 'Draft'],
            ['emp_role' => 'pm', 'task' => 'Sprint planning & stakeholder sync', 'date' => '2026-04-28', 'hours' => 4, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'pm', 'task' => 'Risk assessment & timeline update', 'date' => '2026-04-30', 'hours' => 3, 'billable' => true, 'status' => 'Pending'],
            ['emp_role' => 'qa', 'task' => 'E2E test suite for checkout flow', 'date' => '2026-04-29', 'hours' => 6, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'qa', 'task' => 'Regression testing — auth module', 'date' => '2026-04-30', 'hours' => 5, 'billable' => true, 'status' => 'Draft'],
            ['emp_role' => 'design', 'task' => 'Design system documentation & Figma handoff', 'date' => '2026-04-28', 'hours' => 6, 'billable' => true, 'status' => 'Approved'],
            ['emp_role' => 'backend', 'task' => 'DB migration scripts & seed data', 'date' => '2026-05-01', 'hours' => 4, 'billable' => false, 'status' => 'Draft'],
        ];

        foreach ($entries as $entry) {
            $emp = $employees->first(fn ($e) => $e->capacity_role === $entry['emp_role']);
            if (! $emp) {
                continue;
            }

            DB::table('time_entries')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $this->tenantId,
                'project_id' => $greenmartProjectId,
                'employee_id' => $emp->id,
                'approved_by' => $entry['status'] === 'Approved' ? $adminUserId : null,
                'task' => $entry['task'],
                'date' => $entry['date'],
                'hours' => $entry['hours'],
                'billable' => $entry['billable'],
                'status' => $entry['status'],
                'approved_at' => $entry['status'] === 'Approved' ? $entry['date'].' 18:00:00' : null,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
