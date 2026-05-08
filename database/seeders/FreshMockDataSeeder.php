<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreshMockDataSeeder extends Seeder
{
    private array $tenants = [];
    private array $departments = [];
    private array $roles = [];
    private array $employees = [];
    private array $deals = [];
    private array $contracts = [];
    private array $projects = [];

    public function run(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->cleanup();
        $this->seedTenants();
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
        DB::statement('PRAGMA foreign_keys = ON');
        $this->verify();
    }

    private function cleanup(): void
    {
        DB::table('time_entries')->delete();
        DB::table('invoices')->delete();
        DB::table('milestones')->delete();
        DB::table('projects')->delete();
        DB::table('contracts')->delete();
        DB::table('deal_hard_assignments')->delete();
        DB::table('deal_ghost_roles')->delete();
        DB::table('estimation_versions')->delete();
        DB::table('deals')->delete();
        DB::table('global_overheads')->delete();
        DB::table('company_settings')->delete();
        DB::table('employees')->delete();
        DB::table('roles')->delete();
        DB::table('departments')->delete();
        DB::table('ai_usage_logs')->delete();
        // Remove extra tenants except ANKA Agency; keep users, audit_logs, sessions, password_reset_tokens
        DB::table('tenants')->where('id', '!=', 'aa24b68f-9de2-4621-b404-fb3edd318ee6')->delete();
    }

    private function seedTenants(): void
    {
        $now = now()->toDateTimeString();

        $tenantData = [
            ['id' => 'aa24b68f-9de2-4621-b404-fb3edd318ee6', 'name' => 'ANKA Agency', 'slug' => 'anka-agency', 'plan' => 'pro', 'currency' => 'MMK', 'is_active' => true],
            ['id' => (string) Str::uuid(), 'name' => 'Tokyo Digital Solutions', 'slug' => 'tokyo-digital', 'plan' => 'enterprise', 'currency' => 'JPY', 'is_active' => true],
            ['id' => (string) Str::uuid(), 'name' => 'Yangon Tech Hub', 'slug' => 'yangon-tech', 'plan' => 'pro', 'currency' => 'MMK', 'is_active' => true],
            ['id' => (string) Str::uuid(), 'name' => 'Osaka Software Labs', 'slug' => 'osaka-labs', 'plan' => 'starter', 'currency' => 'JPY', 'is_active' => true],
            ['id' => (string) Str::uuid(), 'name' => 'Mandalay Systems', 'slug' => 'mandalay-sys', 'plan' => 'free', 'currency' => 'MMK', 'is_active' => false],
        ];

        foreach ($tenantData as $t) {
            DB::table('tenants')->updateOrInsert(
                ['id' => $t['id']],
                array_merge($t, ['created_at' => $now, 'updated_at' => $now])
            );
            $this->tenants[$t['id']] = $t;
        }

        // Update existing users to point to ANKA Agency
        DB::table('users')->update(['tenant_id' => 'aa24b68f-9de2-4621-b404-fb3edd318ee6']);
    }

    private function seedDepartments(): void
    {
        $now = now()->toDateTimeString();
        $deptNames = [
            'Engineering', 'Design', 'Project Management', 'Quality Assurance',
            'Business Development', 'Marketing', 'Human Resources', 'Finance',
            'DevOps', 'Data Science', 'Mobile Development', 'Backend Services',
            'Frontend Services', 'Security', 'Customer Support',
        ];

        foreach ($this->tenants as $tenantId => $tenant) {
            foreach ($deptNames as $deptName) {
                $id = (string) Str::uuid();
                $this->departments[$tenantId][$deptName] = $id;
                DB::table('departments')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'name' => $deptName,
                    'manager' => $this->randomName($tenant['currency']),
                    'headcount' => rand(3, 12),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedRoles(): void
    {
        $now = now()->toDateTimeString();
        $roleDefs = [
            ['title' => 'Senior Backend Engineer', 'department' => 'Backend Services', 'rate' => 95],
            ['title' => 'Senior Frontend Engineer', 'department' => 'Frontend Services', 'rate' => 90],
            ['title' => 'Full Stack Engineer', 'department' => 'Engineering', 'rate' => 92],
            ['title' => 'Junior Backend Engineer', 'department' => 'Backend Services', 'rate' => 55],
            ['title' => 'Junior Frontend Engineer', 'department' => 'Frontend Services', 'rate' => 50],
            ['title' => 'UI/UX Designer', 'department' => 'Design', 'rate' => 78],
            ['title' => 'Visual Designer', 'department' => 'Design', 'rate' => 68],
            ['title' => 'Project Manager', 'department' => 'Project Management', 'rate' => 82],
            ['title' => 'Scrum Master', 'department' => 'Project Management', 'rate' => 75],
            ['title' => 'QA Engineer', 'department' => 'Quality Assurance', 'rate' => 62],
            ['title' => 'QA Lead', 'department' => 'Quality Assurance', 'rate' => 78],
            ['title' => 'Sales Executive', 'department' => 'Business Development', 'rate' => 72],
            ['title' => 'Marketing Manager', 'department' => 'Marketing', 'rate' => 70],
            ['title' => 'HR Manager', 'department' => 'Human Resources', 'rate' => 65],
            ['title' => 'DevOps Engineer', 'department' => 'DevOps', 'rate' => 88],
        ];

        foreach ($this->tenants as $tenantId => $tenant) {
            foreach ($roleDefs as $roleDef) {
                $deptId = $this->departments[$tenantId][$roleDef['department']] ?? null;
                $id = (string) Str::uuid();
                $this->roles[$tenantId][$roleDef['title']] = $id;
                DB::table('roles')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'department_id' => $deptId,
                    'title' => $roleDef['title'],
                    'department' => $roleDef['department'],
                    'rate' => $roleDef['rate'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedEmployees(): void
    {
        $now = now()->toDateTimeString();
        $mmkNames = ['Aung Khant', 'Thiha Soe', 'Min Hein', 'Thiri Aye', 'Zaw Lin', 'Hnin Wai', 'Myo Aung', 'Wai Phyo', 'Khin Thu', 'Su Mon', 'Nyein Chan', 'Aye Thin', 'Paing Soe', 'Ei Shwe', 'Thandar Win'];
        $jpyNames = ['Takeshi Yamamoto', 'Yuki Tanaka', 'Haruto Sato', 'Sakura Watanabe', 'Riku Ito', 'Akira Kobayashi', 'Mei Nakamura', 'Ren Suzuki', 'Hana Takahashi', 'Daiki Yamada', 'Yui Kimura', 'Kaito Yoshida', 'Rin Sasaki', 'Sora Matsumoto', 'Aoi Inoue'];

        foreach ($this->tenants as $tenantId => $tenant) {
            $names = $tenant['currency'] === 'JPY' ? $jpyNames : $mmkNames;
            $roleTitles = array_keys($this->roles[$tenantId]);
            $salaryBase = $tenant['currency'] === 'JPY' ? 350000 : 2800;

            foreach ($names as $i => $name) {
                $roleTitle = $roleTitles[$i % count($roleTitles)];
                $role = DB::table('roles')->where('id', $this->roles[$tenantId][$roleTitle])->first();
                $salary = $salaryBase * (0.8 + ($role->rate / 100));

                $id = (string) Str::uuid();
                $this->employees[$tenantId][$name] = $id;
                DB::table('employees')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'department_id' => $role->department_id,
                    'job_role_id' => $role->id,
                    'name' => $name,
                    'role_name' => $roleTitle,
                    'capacity_role' => match(true) {
                        str_contains($roleTitle, 'Backend') => 'backend',
                        str_contains($roleTitle, 'Frontend') => 'frontend',
                        str_contains($roleTitle, 'Designer') => 'design',
                        str_contains($roleTitle, 'QA') => 'qa',
                        str_contains($roleTitle, 'Manager') || str_contains($roleTitle, 'Scrum') => 'pm',
                        default => null,
                    },
                    'monthly_salary' => round($salary, 2),
                    'workable_hours' => 160,
                    'status' => $i < 13 ? 'Active' : 'On Leave',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedCompanySettings(): void
    {
        foreach ($this->tenants as $tenantId => $tenant) {
            $isJPY = $tenant['currency'] === 'JPY';
            DB::table('company_settings')->updateOrInsert(
                ['tenant_id' => $tenantId],
                [
                    'id' => 'singleton-' . $tenantId,
                    'tenant_id' => $tenantId,
                    'overhead_percentage' => 20.00,
                    'buffer_percentage' => 10.00,
                    'yearly_fixed_cost' => $isJPY ? 12000000 : 120000,
                    'employer_tax_percentage' => 8.00,
                    'benefits_percentage' => 12.00,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedGlobalOverheads(): void
    {
        $now = now()->toDateTimeString();
        $overheadCats = [
            ['category' => 'Office Rent', 'description' => 'Monthly office lease', 'monthly_cost_mm' => 4500, 'monthly_cost_jp' => 450000],
            ['category' => 'Internet & Telecom', 'description' => 'Fiber and VPN services', 'monthly_cost_mm' => 800, 'monthly_cost_jp' => 80000],
            ['category' => 'Software Licenses', 'description' => 'Dev tools and cloud', 'monthly_cost_mm' => 2200, 'monthly_cost_jp' => 220000],
            ['category' => 'Accounting & Legal', 'description' => 'Bookkeeping and legal', 'monthly_cost_mm' => 1500, 'monthly_cost_jp' => 150000],
            ['category' => 'Marketing & Events', 'description' => 'Social media and branding', 'monthly_cost_mm' => 1000, 'monthly_cost_jp' => 100000],
        ];

        foreach ($this->tenants as $tenantId => $tenant) {
            $isJPY = $tenant['currency'] === 'JPY';
            foreach ($overheadCats as $oh) {
                DB::table('global_overheads')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'category' => $oh['category'],
                    'description' => $oh['description'],
                    'monthly_cost' => $isJPY ? $oh['monthly_cost_jp'] : $oh['monthly_cost_mm'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedDeals(): void
    {
        $now = now()->toDateTimeString();
        $dealNames = [
            'GreenMart E-Commerce', 'MedConnect Telehealth', 'Fintech Dashboard', 'TravelOK Booking',
            'EduNext LMS', 'SmartFactory IoT', 'AgriTech Monitoring', 'LogiChain Supply',
            'PayQuick Wallet', 'HealthTrack EMR', 'CityMap Navigation', 'FoodOrder Platform',
            'StreamFlix Video', 'CloudVault Storage', 'AutoDrive Fleet',
        ];
        $clients = [
            'GreenMart Holdings', 'MedConnect Health', 'Aya Bank Digital', 'TravelOK Group',
            'EduNext Academy', 'Myanmar Industrial Corp', 'Golden Harvest Co', 'FastTrack Logistics',
            'SpeedPay Inc', 'Wellness Clinics', 'MetroCity Gov', 'TastyBites Restaurant',
            'MediaStream Corp', 'DataSafe Solutions', 'AutoFleet Myanmar',
        ];
        $statuses = ['inquiry', 'lead', 'proposal', 'contract', 'won', 'lost'];
        $statusWeights = [15, 20, 25, 20, 15, 5];

        foreach ($this->tenants as $tenantId => $tenant) {
            $isJPY = $tenant['currency'] === 'JPY';
            $budgetBase = $isJPY ? 5000000 : 50000;

            foreach ($dealNames as $i => $name) {
                // Force first 3 deals per tenant to 'won' to ensure enough contracts/projects
                $status = $i < 3 ? 'won' : $this->weightedRandom($statuses, $statusWeights);
                $budget = $budgetBase * (1 + $i * 0.3);
                $timeline = rand(3, 12);
                $hours = $timeline * 400;
                $laborCost = $budget * 0.55;
                $overhead = $laborCost * 0.20;
                $buffer = $laborCost * 0.10;
                $totalCost = $laborCost + $overhead + $buffer;
                $profit = $budget - $totalCost;

                $id = (string) Str::uuid();
                $this->deals[$tenantId][$name] = $id;
                DB::table('deals')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'client' => $clients[$i],
                    'status' => $status,
                    'win_probability' => match($status) {
                        'won' => 100,
                        'contract' => 90,
                        'proposal' => 75,
                        'lead' => 40,
                        'inquiry' => 25,
                        'lost' => 0,
                        default => 50,
                    },
                    'client_budget' => $budget,
                    'timeline_months' => $timeline,
                    'workload_hours' => $hours,
                    'workload_description' => 'Comprehensive ' . strtolower($name) . ' development and deployment.',
                    'target_margin' => 30,
                    'base_labor_cost' => $laborCost,
                    'overhead_cost' => $overhead,
                    'buffer_cost' => $buffer,
                    'total_estimated_cost' => $totalCost,
                    'estimated_gross_profit' => $profit,
                    'estimated_value' => $budget,
                    'won_at' => $status === 'won' ? now()->subMonths(rand(1, 6)) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->seedGhostRoles();
    }

    private function seedGhostRoles(): void
    {
        $now = now()->toDateTimeString();
        $roleTypes = ['backend', 'frontend', 'design', 'qa', 'pm'];

        foreach ($this->tenants as $tenantId => $tenant) {
            $isJPY = $tenant['currency'] === 'JPY';
            $salaryBase = $isJPY ? 350000 : 3000;

            foreach ($this->deals[$tenantId] as $dealName => $dealId) {
                $numRoles = rand(2, 5);
                for ($i = 0; $i < $numRoles; $i++) {
                    DB::table('deal_ghost_roles')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'deal_id' => $dealId,
                        'role_type' => $roleTypes[$i % count($roleTypes)],
                        'quantity' => rand(1, 4),
                        'months' => rand(3, 10),
                        'avg_monthly_salary' => $salaryBase * (0.8 + rand(1, 5) / 10),
                        'min_monthly_salary' => $salaryBase * 0.7,
                        'max_monthly_salary' => $salaryBase * 1.3,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function seedContractsAndProjects(): void
    {
        $now = now()->toDateTimeString();
        $contractNum = 1;

        foreach ($this->tenants as $tenantId => $tenant) {
            foreach ($this->deals[$tenantId] as $dealName => $dealId) {
                $deal = DB::table('deals')->where('id', $dealId)->first();
                if ($deal->status !== 'won') continue;

                $contractId = (string) Str::uuid();
                $this->contracts[$tenantId][$dealName] = $contractId;

                DB::table('contracts')->insert([
                    'id' => $contractId,
                    'tenant_id' => $tenantId,
                    'deal_id' => $dealId,
                    'contract_number' => sprintf('CON-%04d', $contractNum++),
                    'client' => $deal->client,
                    'total_value' => $deal->estimated_value,
                    'revenue_recognized' => $deal->estimated_value * 0.3,
                    'status' => 'Active',
                    'start_date' => now()->subMonths(rand(1, 4))->format('Y-m-d'),
                    'end_date' => now()->addMonths(rand(3, 8))->format('Y-m-d'),
                    'notes' => 'Standard service agreement. Monthly billing.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $projectId = (string) Str::uuid();
                $this->projects[$tenantId][$dealName] = $projectId;

                DB::table('projects')->insert([
                    'id' => $projectId,
                    'tenant_id' => $tenantId,
                    'contract_id' => $contractId,
                    'project_number' => sprintf('PRJ-%03d', $contractNum - 1),
                    'name' => $deal->name,
                    'client' => $deal->client,
                    'budget_hours' => $deal->workload_hours,
                    'consumed_hours' => $deal->workload_hours * 0.35,
                    'status' => ['On Track', 'At Risk', 'Over Budget'][rand(0, 2)],
                    'start_date' => now()->subMonths(rand(1, 4))->format('Y-m-d'),
                    'end_date' => now()->addMonths(rand(3, 8))->format('Y-m-d'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedMilestones(): void
    {
        $now = now()->toDateTimeString();
        $milestoneNames = [
            'Phase 1: Discovery', 'Phase 2: MVP Development', 'Phase 3: Integration',
            'Phase 4: QA & Testing', 'Phase 5: Launch & Handoff',
        ];

        foreach ($this->tenants as $tenantId => $tenant) {
            foreach ($this->contracts[$tenantId] ?? [] as $dealName => $contractId) {
                $contract = DB::table('contracts')->where('id', $contractId)->first();
                $amountPerPhase = $contract->total_value / count($milestoneNames);

                foreach ($milestoneNames as $i => $name) {
                    DB::table('milestones')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'contract_id' => $contractId,
                        'name' => $name,
                        'due_date' => now()->addMonths($i + 1)->format('Y-m-d'),
                        'amount' => $amountPerPhase,
                        'status' => $i < 2 ? 'Completed' : ($i === 2 ? 'In Progress' : 'Pending'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function seedInvoices(): void
    {
        $now = now()->toDateTimeString();
        $invoiceNum = 1001;

        foreach ($this->tenants as $tenantId => $tenant) {
            foreach ($this->contracts[$tenantId] ?? [] as $dealName => $contractId) {
                $contract = DB::table('contracts')->where('id', $contractId)->first();
                $milestoneIds = DB::table('milestones')
                    ->where('contract_id', $contractId)
                    ->pluck('id')
                    ->toArray();

                for ($i = 0; $i < 3; $i++) {
                    $amount = $contract->total_value * (0.3 + $i * 0.15);
                    $status = $i < 2 ? 'Paid' : 'Pending';
                    $paidAt = $status === 'Paid' ? now()->subDays(rand(10, 60))->format('Y-m-d H:i:s') : null;

                    DB::table('invoices')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'contract_id' => $contractId,
                        'milestone_id' => $milestoneIds[$i] ?? null,
                        'invoice_number' => sprintf('INV-%04d', $invoiceNum++),
                        'issue_date' => now()->subMonths(2 - $i)->format('Y-m-d'),
                        'due_date' => now()->subMonths(1 - $i)->format('Y-m-d'),
                        'amount' => $amount,
                        'tax' => $amount * 0.08,
                        'status' => $status,
                        'paid_at' => $paidAt,
                        'notes' => "Phase " . ($i + 1) . " billing",
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function seedTimeEntries(): void
    {
        $now = now()->toDateTimeString();
        $tasks = [
            'API development', 'Frontend component build', 'Database migration',
            'UI/UX design review', 'E2E testing', 'Sprint planning',
            'Code review', 'Client meeting', 'Documentation',
            'Bug fixing', 'Performance optimization', 'Security audit',
            'Feature deployment', 'User feedback analysis', 'Integration testing',
        ];

        foreach ($this->tenants as $tenantId => $tenant) {
            $employeeIds = array_values($this->employees[$tenantId]);
            $projectIds = array_values($this->projects[$tenantId] ?? []);

            if (empty($projectIds)) continue;

            for ($i = 0; $i < 20; $i++) {
                $empId = $employeeIds[$i % count($employeeIds)];
                $projId = $projectIds[$i % count($projectIds)];
                $date = now()->subDays(rand(1, 30))->format('Y-m-d');

                DB::table('time_entries')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'project_id' => $projId,
                    'employee_id' => $empId,
                    'task' => $tasks[$i % count($tasks)],
                    'date' => $date,
                    'hours' => rand(4, 10) + (rand(0, 1) ? 0.5 : 0),
                    'billable' => $i < 15,
                    'status' => ['Approved', 'Pending', 'Draft'][rand(0, 2)],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function verify(): void
    {
        $tables = [
            'tenants' => 'Tenants',
            'departments' => 'Departments',
            'roles' => 'Roles',
            'employees' => 'Employees',
            'deals' => 'Deals',
            'deal_ghost_roles' => 'Deal Ghost Roles',
            'contracts' => 'Contracts',
            'projects' => 'Projects',
            'milestones' => 'Milestones',
            'invoices' => 'Invoices',
            'time_entries' => 'Time Entries',
            'users' => 'Users (kept)',
        ];
        echo "\n=== VERIFICATION ===\n";
        foreach ($tables as $table => $label) {
            $count = DB::table($table)->count();
            echo sprintf("%-25s : %d\n", $label, $count);
        }
        echo "\nTenant currencies:\n";
        foreach (DB::table('tenants')->get(['name', 'currency', 'plan', 'is_active']) as $t) {
            echo sprintf("  %-25s %s %s %s\n", $t->name, $t->currency, $t->plan, $t->is_active ? 'active' : 'inactive');
        }
    }

    private function weightedRandom(array $values, array $weights): mixed
    {
        $total = array_sum($weights);
        $rand = mt_rand(1, $total);
        $current = 0;
        foreach ($values as $i => $value) {
            $current += $weights[$i];
            if ($rand <= $current) return $value;
        }
        return $values[0];
    }

    private function randomName(string $currency): string
    {
        $mmk = ['Aung Khant', 'Thiha Soe', 'Min Hein', 'Thiri Aye', 'Zaw Lin', 'Hnin Wai', 'Myo Aung', 'Wai Phyo', 'Khin Thu', 'Su Mon'];
        $jpy = ['Takeshi Yamamoto', 'Yuki Tanaka', 'Haruto Sato', 'Sakura Watanabe', 'Riku Ito', 'Akira Kobayashi', 'Mei Nakamura', 'Ren Suzuki', 'Hana Takahashi', 'Daiki Yamada'];
        $names = $currency === 'JPY' ? $jpy : $mmk;
        return $names[array_rand($names)];
    }
}
