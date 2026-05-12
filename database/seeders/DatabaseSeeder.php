<?php

namespace Database\Seeders;

use App\Models\AiUsageLog;
use App\Models\CapacityRole;
use App\Models\CompanySetting;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealContractDocument;
use App\Models\DealGhostRole;
use App\Models\DealHardAssignment;
use App\Models\DealOverhead;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\EstimationResource;
use App\Models\EstimationVersion;
use App\Models\GlobalOverhead;
use App\Models\Invoice;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTeamAssignment;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'Demo@1234';

    public function run(): void
    {
        Model::unguarded(function () {
            $this->cleanDatabase();
            $this->createOwner();

            foreach ($this->tenantBlueprints() as $blueprint) {
                $this->seedTenant($blueprint);
            }
        });
    }

    private function cleanDatabase(): void
    {
        Schema::disableForeignKeyConstraints();

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
            'capacity_roles',
            'roles',
            'departments',
            'global_overheads',
            'company_settings',
            'tenants',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    private function createOwner(): void
    {
        User::create([
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'email' => 'owner@anka.app',
            'password' => Hash::make(self::PASSWORD),
            'system_role' => 'owner',
            'app_role' => 'Admin',
            'is_super_admin' => true,
        ]);
    }

    private function seedTenant(array $blueprint): void
    {
        $tenant = Tenant::create([
            'name' => $blueprint['name'],
            'slug' => $blueprint['slug'],
            'plan' => 'pro',
            'currency' => $blueprint['currency'],
            'is_active' => true,
        ]);

        $capacityRoles = $this->createCapacityRoles($tenant);
        $departments = $this->createDepartments($tenant);
        $roles = $this->createRoles($tenant, $departments, $blueprint['role_rates']);
        $skills = $this->createSkills($tenant, $blueprint['skills']);
        $employees = $this->createEmployees($tenant, $blueprint['employees'], $departments, $roles, $capacityRoles, $skills);
        $users = $this->createUsers($tenant, $blueprint['users'], $employees);

        $this->finishDepartments($departments, $employees);
        $this->createCompanySettings($tenant, $blueprint['settings']);
        $this->createOverheads($tenant, $blueprint['overheads']);
        $this->createDealsAndDelivery($tenant, $blueprint, $employees, $roles, $users);
        $this->createAiUsage($tenant, $users);
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

    private function createDepartments(Tenant $tenant): array
    {
        $departments = [];

        foreach (['Sales', 'Delivery', 'Product Design', 'Operations', 'Finance'] as $name) {
            $departments[$name] = Department::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'headcount' => 0,
            ]);
        }

        return $departments;
    }

    private function createRoles(Tenant $tenant, array $departments, array $rates): array
    {
        $roleDepartments = [
            'Account Director' => 'Sales',
            'Solution Architect' => 'Delivery',
            'Backend Engineer' => 'Delivery',
            'Frontend Engineer' => 'Delivery',
            'Product Designer' => 'Product Design',
            'QA Engineer' => 'Delivery',
            'Project Manager' => 'Operations',
            'Finance Manager' => 'Finance',
        ];

        $roles = [];

        foreach ($roleDepartments as $title => $departmentName) {
            $department = $departments[$departmentName];
            $roles[$title] = Role::create([
                'tenant_id' => $tenant->id,
                'department_id' => $department->id,
                'title' => $title,
                'department' => $department->name,
                'rate' => $rates[$title],
            ]);
        }

        return $roles;
    }

    private function createSkills(Tenant $tenant, array $skillNames): array
    {
        $skills = [];

        foreach ($skillNames as $name => $category) {
            $skills[$name] = Skill::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'category' => $category,
            ]);
        }

        return $skills;
    }

    private function createEmployees(
        Tenant $tenant,
        array $employeeRows,
        array $departments,
        array $roles,
        array $capacityRoles,
        array $skills
    ): array {
        $employees = [];

        foreach ($employeeRows as $row) {
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
                'monthly_salary' => $row['salary'],
                'workable_hours' => $row['hours'],
                'status' => $row['status'] ?? 'Active',
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

    private function createUsers(Tenant $tenant, array $userRows, array $employees): array
    {
        $users = [];

        foreach ($userRows as $row) {
            $employee = $employees[$row['employee']];
            $user = User::create([
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

            $users[$row['key']] = $user;
        }

        return $users;
    }

    private function finishDepartments(array $departments, array $employees): void
    {
        $managerByDepartment = [
            'Sales' => 'sales_lead',
            'Delivery' => 'solution_architect',
            'Product Design' => 'designer',
            'Operations' => 'project_manager',
            'Finance' => 'finance_manager',
        ];

        foreach ($departments as $name => $department) {
            $headcount = collect($employees)
                ->filter(fn (Employee $employee) => $employee->department_id === $department->id)
                ->count();

            $managerKey = $managerByDepartment[$name] ?? null;
            $manager = $managerKey && isset($employees[$managerKey]) ? $employees[$managerKey] : null;

            $department->update([
                'manager_id' => $manager?->id,
                'manager' => $manager?->name,
                'headcount' => $headcount,
            ]);
        }
    }

    private function createCompanySettings(Tenant $tenant, array $settings): void
    {
        CompanySetting::create([
            'id' => $tenant->id,
            'tenant_id' => $tenant->id,
            'overhead_percentage' => $settings['overhead_percentage'],
            'buffer_percentage' => $settings['buffer_percentage'],
            'yearly_fixed_cost' => $settings['yearly_fixed_cost'],
            'employer_tax_percentage' => $settings['employer_tax_percentage'],
            'benefits_percentage' => $settings['benefits_percentage'],
            'cost_to_bill_ratio' => $settings['cost_to_bill_ratio'],
            'default_monthly_capacity_hours' => 160,
            'fallback_hourly_cost' => $settings['fallback_hourly_cost'],
        ]);
    }

    private function createOverheads(Tenant $tenant, array $overheads): void
    {
        foreach ($overheads as $row) {
            GlobalOverhead::create([
                'tenant_id' => $tenant->id,
                'category' => $row['category'],
                'description' => $row['description'],
                'monthly_cost' => $row['monthly_cost'],
                'effective_month' => $row['effective_month'] ?? null,
                'effective_year' => $row['effective_year'] ?? null,
            ]);
        }
    }

    private function createDealsAndDelivery(Tenant $tenant, array $blueprint, array $employees, array $roles, array $users): void
    {
        foreach ($blueprint['deals'] as $index => $dealRow) {
            $deal = $this->createDeal($tenant, $dealRow, $employees, $roles, $users['admin']);

            if (($dealRow['delivery'] ?? null) !== null) {
                $this->createDelivery($tenant, $deal, $dealRow['delivery'], $employees, $users['admin']);
            }
        }
    }

    private function createDeal(Tenant $tenant, array $row, array $employees, array $roles, User $admin): Deal
    {
        $assignments = $this->buildAssignments($row['assignments'] ?? [], $employees);
        $baseLaborCost = $this->assignmentCost($assignments, $employees);
        $dealOverheads = collect($row['overheads'] ?? [])->sum('cost');
        $overheadCost = round(($baseLaborCost * ($row['overhead_pct'] ?? 0.18)) + $dealOverheads, 2);
        $bufferCost = round(($baseLaborCost + $overheadCost) * ($row['buffer_pct'] ?? 0.08), 2);
        $totalCost = round($baseLaborCost + $overheadCost + $bufferCost, 2);
        $grossProfit = round(($row['client_budget'] ?? $row['estimated_value']) - $totalCost, 2);

        $deal = Deal::create([
            'tenant_id' => $tenant->id,
            'name' => $row['name'],
            'client' => $row['client'],
            'contact_name' => $row['contact']['name'],
            'contact_email' => $row['contact']['email'],
            'contact_phone' => $row['contact']['phone'],
            'estimated_value' => $row['estimated_value'],
            'win_probability' => $row['probability'],
            'status' => $row['status'],
            'expected_close_date' => $row['expected_close'],
            'lead_source' => $row['lead_source'],
            'client_budget' => $row['client_budget'],
            'timeline_months' => $row['timeline_months'],
            'workload_hours' => $row['workload_hours'],
            'workload_description' => $row['description'],
            'target_margin' => $row['target_margin'],
            'base_labor_cost' => $baseLaborCost,
            'overhead_cost' => $overheadCost,
            'buffer_cost' => $bufferCost,
            'total_estimated_cost' => $totalCost,
            'estimated_gross_profit' => $grossProfit,
            'won_at' => $row['status'] === 'won' ? Carbon::parse($row['won_at']) : null,
            'lost_at' => $row['status'] === 'lost' ? Carbon::parse($row['lost_at']) : null,
            'win_reason' => $row['win_reason'] ?? null,
            'loss_reason' => $row['loss_reason'] ?? null,
            'wizard_step' => $row['wizard_step'] ?? 'complete',
        ]);

        foreach ($row['ghost_roles'] as $role) {
            $range = $this->salaryRange($employees, $role['role_type']);
            DealGhostRole::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'role_type' => $role['role_type'],
                'quantity' => $role['quantity'],
                'months' => $role['months'] ?? 100,
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

        foreach ($row['resources'] as $resource) {
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

        foreach ($row['overheads'] ?? [] as $overhead) {
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
            'resources' => array_map(fn ($resource) => [
                'roleId' => $resource['role_code'],
                'featureName' => $resource['feature'],
                'hours' => $resource['hours'],
            ], $row['resources']),
            'overheads' => array_map(fn ($overhead) => [
                'name' => $overhead['name'],
                'cost' => $overhead['cost'],
            ], $row['overheads'] ?? []),
            'target_margin' => $row['target_margin'],
            'notes' => 'Initial customer-ready estimate used for demo scenario.',
            'created_by' => $admin->id,
            'created_at' => Carbon::parse($row['expected_close'])->subDays(14),
        ]);

        // Seed demo contract documents for deals in the A-rank (negotiation)
        // stage so the new contract-AI gate UI has data on first load.
        // The actual file isn't materialised — only the metadata + analysis
        // verdict. Re-running the analyser on these rows would 404 because
        // storage_path is fictional, which is fine for demo purposes.
        if ($deal->status === 'negotiation') {
            $this->seedDemoContractDocuments($tenant, $deal, $admin);
        }

        return $deal;
    }

    /**
     * Demo `deal_contract_documents` rows. Uses a small status rotation
     * keyed by deal id so different negotiation deals show different AI
     * verdicts (rejected / pending / failed) across the demo dataset.
     *
     * `approved` is intentionally NOT seeded here — in production an
     * `approved` analysis auto-fires win_deal() so the deal would have
     * already left `negotiation`. Seeding that combination would model an
     * impossible state.
     */
    private function seedDemoContractDocuments(Tenant $tenant, Deal $deal, User $admin): void
    {
        // Pick a status from a deterministic rotation based on the deal's UUID
        // so re-seeding produces the same demo layout.
        $bucket = hexdec(substr(str_replace('-', '', $deal->id), 0, 4)) % 3;
        $scenario = ['rejected', 'pending', 'failed'][$bucket];

        $base = [
            'tenant_id' => $tenant->id,
            'deal_id' => $deal->id,
            'uploaded_by' => $admin->id,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'storage_path' => sprintf('contract-docs/%s/%s/demo-seed.pdf', $tenant->id, $deal->id),
        ];

        if ($scenario === 'rejected') {
            DealContractDocument::create($base + [
                'original_filename' => Str::slug($deal->client).'-draft-contract.pdf',
                'size_bytes' => 412_300,
                'analysis_status' => 'rejected',
                'analysis_result' => [
                    'approved' => false,
                    'missing_fields' => ['payment_terms', 'effective_date'],
                    'reasoning' => 'Draft contract includes scope and signatures, but no payment schedule or commencement date — both required before this deal can move to Won.',
                    'required_fields' => ['client_name', 'contract_value', 'payment_terms', 'effective_date', 'signatures', 'scope_of_work'],
                    'model' => 'claude-3-5-sonnet-latest',
                ],
                'analyzed_at' => now()->subDays(2),
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ]);

            return;
        }

        if ($scenario === 'failed') {
            DealContractDocument::create($base + [
                'original_filename' => Str::slug($deal->client).'-scanned-contract.pdf',
                'size_bytes' => 1_240_500,
                'analysis_status' => 'failed',
                'analysis_result' => [
                    'error' => 'Document contained no extractable text.',
                    'suggestion' => 'The file appears to be image-only (scanned). Re-export as a text-based PDF or DOCX and try again.',
                ],
                'analyzed_at' => now()->subHours(20),
                'created_at' => now()->subHours(20),
                'updated_at' => now()->subHours(20),
            ]);

            return;
        }

        // pending — represents a doc uploaded mid-day, analysis still queued / in flight.
        DealContractDocument::create($base + [
            'original_filename' => Str::slug($deal->client).'-signed-contract.pdf',
            'size_bytes' => 287_900,
            'analysis_status' => 'pending',
            'analysis_result' => null,
            'analyzed_at' => null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
    }

    private function createDelivery(Tenant $tenant, Deal $deal, array $row, array $employees, User $admin): void
    {
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'deal_id' => $deal->id,
            'contract_number' => $row['contract_number'],
            'client' => $deal->client,
            'total_value' => $deal->client_budget,
            'revenue_recognized' => 0,
            'status' => $row['contract_status'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'] ?? null,
            'notes' => $row['contract_notes'],
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'project_number' => $row['project_number'],
            'name' => $deal->name,
            'client' => $deal->client,
            'budget_hours' => $deal->workload_hours,
            'consumed_hours' => 0,
            'status' => $row['project_status'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'] ?? null,
        ]);

        $milestones = [];
        foreach ($row['milestones'] as $milestoneRow) {
            $milestone = Milestone::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'name' => $milestoneRow['name'],
                'due_date' => $milestoneRow['due_date'],
                'amount' => $milestoneRow['amount'],
                'status' => $milestoneRow['status'],
                'completed_at' => $milestoneRow['status'] === 'Completed'
                    ? Carbon::parse($milestoneRow['due_date'])->addDays(1)
                    : null,
            ]);
            $milestones[$milestoneRow['key']] = $milestone;
        }

        $recognized = 0;
        foreach ($row['invoices'] as $invoiceRow) {
            Invoice::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'milestone_id' => isset($invoiceRow['milestone']) ? $milestones[$invoiceRow['milestone']]->id : null,
                'invoice_number' => $invoiceRow['number'],
                'issue_date' => $invoiceRow['issue_date'],
                'due_date' => $invoiceRow['due_date'],
                'amount' => $invoiceRow['amount'],
                'tax' => $invoiceRow['tax'],
                'status' => $invoiceRow['status'],
                'paid_at' => $invoiceRow['status'] === 'Paid' ? Carbon::parse($invoiceRow['paid_at']) : null,
                'notes' => $invoiceRow['notes'],
            ]);

            if ($invoiceRow['status'] === 'Paid') {
                $recognized += $invoiceRow['amount'] + $invoiceRow['tax'];
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

        $approvedHours = 0;
        foreach ($row['time_entries'] as $entryRow) {
            $employee = $employees[$entryRow['employee']];
            $entry = TimeEntry::create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'employee_id' => $employee->id,
                'approved_by' => $entryRow['status'] === 'Approved' ? $admin->id : null,
                'task' => $entryRow['task'],
                'date' => $entryRow['date'],
                'hours' => $entryRow['hours'],
                'billable' => $entryRow['billable'] ?? true,
                'status' => $entryRow['status'],
                'notes' => $entryRow['notes'] ?? null,
                'approved_at' => $entryRow['status'] === 'Approved'
                    ? Carbon::parse($entryRow['date'])->addDay()
                    : null,
            ]);

            if ($entry->status === 'Approved') {
                $approvedHours += $entry->hours;
            }
        }

        $project->update(['consumed_hours' => $approvedHours]);
    }

    private function createAiUsage(Tenant $tenant, array $users): void
    {
        foreach ([
            ['ai_team_builder', 'claude-3-5-sonnet-latest', 11800, 3100, 0.082],
            ['ai_chatbot', 'claude-3-5-sonnet-latest', 4200, 900, 0.026],
            ['project_auto_assign', 'demo-fallback', 0, 0, 0],
        ] as $row) {
            AiUsageLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => $users['admin']->id,
                'feature' => $row[0],
                'model' => $row[1],
                'input_tokens' => $row[2],
                'output_tokens' => $row[3],
                'estimated_cost_usd' => $row[4],
            ]);
        }
    }

    private function buildAssignments(array $rows, array $employees): array
    {
        $assignments = [];

        foreach ($rows as $employeeKey => $hours) {
            if (isset($employees[$employeeKey])) {
                $assignments[] = [
                    'employee' => $employees[$employeeKey],
                    'hours' => $hours,
                ];
            }
        }

        return $assignments;
    }

    private function assignmentCost(array $assignments, array $employees): float
    {
        return round(collect($assignments)->sum(function (array $assignment) {
            return $assignment['hours'] * $this->hourlyCost($assignment['employee']);
        }), 2);
    }

    private function hourlyCost(Employee $employee): float
    {
        return $employee->workable_hours > 0
            ? round($employee->monthly_salary / $employee->workable_hours, 4)
            : 0.0;
    }

    private function salaryRange(array $employees, string $capacityRole): array
    {
        $matches = collect($employees)
            ->filter(fn (Employee $employee) => $employee->capacity_role === $capacityRole && $employee->status === 'Active');

        if ($matches->isEmpty()) {
            return ['min' => 0, 'max' => 0];
        }

        return [
            'min' => (float) $matches->min('monthly_salary'),
            'max' => (float) $matches->max('monthly_salary'),
        ];
    }

    private function tenantBlueprints(): array
    {
        return [
            $this->yangonDigitalWorks(),
            $this->mandalayStudio(),
            $this->tokyoProductLab(),
        ];
    }

    private function yangonDigitalWorks(): array
    {
        return [
            'name' => 'Yangon Digital Works',
            'slug' => 'yangon-digital-works',
            'currency' => 'MMK',
            'role_rates' => [
                'Account Director' => 95000,
                'Solution Architect' => 90000,
                'Backend Engineer' => 76000,
                'Frontend Engineer' => 68000,
                'Product Designer' => 62000,
                'QA Engineer' => 45000,
                'Project Manager' => 70000,
                'Finance Manager' => 52000,
            ],
            'skills' => [
                'Laravel' => 'Technical',
                'React' => 'Technical',
                'PostgreSQL' => 'Technical',
                'AWS' => 'Technical',
                'Figma' => 'Creative',
                'QA Automation' => 'Technical',
                'Scrum Delivery' => 'Management',
                'Fintech Compliance' => 'Financial',
            ],
            'employees' => [
                ['key' => 'sales_lead', 'name' => 'Mya Thandar', 'department' => 'Sales', 'role' => 'Account Director', 'capacity' => 'pm', 'salary' => 2400000, 'hours' => 160, 'skills' => ['Scrum Delivery' => 'expert', 'Fintech Compliance' => 'intermediate']],
                ['key' => 'solution_architect', 'name' => 'Aung Kyaw Min', 'department' => 'Delivery', 'role' => 'Solution Architect', 'capacity' => 'backend', 'salary' => 2800000, 'hours' => 160, 'skills' => ['Laravel' => 'expert', 'PostgreSQL' => 'expert', 'AWS' => 'intermediate']],
                ['key' => 'backend_one', 'name' => 'Htet Wai Yan', 'department' => 'Delivery', 'role' => 'Backend Engineer', 'capacity' => 'backend', 'salary' => 2100000, 'hours' => 160, 'skills' => ['Laravel' => 'expert', 'PostgreSQL' => 'intermediate']],
                ['key' => 'backend_two', 'name' => 'Nyein Chan Ko', 'department' => 'Delivery', 'role' => 'Backend Engineer', 'capacity' => 'backend', 'salary' => 1900000, 'hours' => 160, 'skills' => ['Laravel' => 'intermediate', 'AWS' => 'intermediate']],
                ['key' => 'frontend_one', 'name' => 'Su Hnin Wai', 'department' => 'Delivery', 'role' => 'Frontend Engineer', 'capacity' => 'frontend', 'salary' => 1850000, 'hours' => 160, 'skills' => ['React' => 'expert', 'Figma' => 'beginner']],
                ['key' => 'designer', 'name' => 'May Zin Htun', 'department' => 'Product Design', 'role' => 'Product Designer', 'capacity' => 'design', 'salary' => 1650000, 'hours' => 152, 'skills' => ['Figma' => 'expert', 'React' => 'beginner']],
                ['key' => 'qa_one', 'name' => 'Ko Pyae Sone', 'department' => 'Delivery', 'role' => 'QA Engineer', 'capacity' => 'qa', 'salary' => 1200000, 'hours' => 160, 'skills' => ['QA Automation' => 'expert']],
                ['key' => 'project_manager', 'name' => 'Ei Mon Khaing', 'department' => 'Operations', 'role' => 'Project Manager', 'capacity' => 'pm', 'salary' => 1750000, 'hours' => 160, 'skills' => ['Scrum Delivery' => 'expert']],
                ['key' => 'finance_manager', 'name' => 'Thet Htar Oo', 'department' => 'Finance', 'role' => 'Finance Manager', 'capacity' => 'pm', 'salary' => 1450000, 'hours' => 152, 'skills' => ['Fintech Compliance' => 'expert']],
            ],
            'users' => [
                ['key' => 'admin', 'employee' => 'sales_lead', 'first_name' => 'Mya', 'last_name' => 'Thandar', 'email' => 'admin@yangonworks.demo', 'role' => 'Admin'],
                ['key' => 'sales', 'employee' => 'sales_lead', 'first_name' => 'Sales', 'last_name' => 'Yangon', 'email' => 'sales@yangonworks.demo', 'role' => 'Sales'],
                ['key' => 'delivery', 'employee' => 'project_manager', 'first_name' => 'Delivery', 'last_name' => 'Yangon', 'email' => 'delivery@yangonworks.demo', 'role' => 'Delivery'],
            ],
            'settings' => ['overhead_percentage' => 22, 'buffer_percentage' => 9, 'yearly_fixed_cost' => 228000000, 'employer_tax_percentage' => 7, 'benefits_percentage' => 11, 'cost_to_bill_ratio' => 0.42, 'fallback_hourly_cost' => 32000],
            'overheads' => [
                ['category' => 'Office Rent', 'description' => 'Bahan office lease and utilities', 'monthly_cost' => 12500000],
                ['category' => 'Cloud Infrastructure', 'description' => 'AWS, monitoring, email, and backups', 'monthly_cost' => 6800000],
                ['category' => 'Sales Travel', 'description' => 'Client visits in Yangon and Naypyidaw', 'monthly_cost' => 3200000, 'effective_month' => 5, 'effective_year' => 2026],
            ],
            'deals' => $this->yangonDeals(),
        ];
    }

    private function mandalayStudio(): array
    {
        return [
            'name' => 'Mandalay Studio Co',
            'slug' => 'mandalay-studio',
            'currency' => 'MMK',
            'role_rates' => [
                'Account Director' => 82000,
                'Solution Architect' => 78000,
                'Backend Engineer' => 64000,
                'Frontend Engineer' => 60000,
                'Product Designer' => 56000,
                'QA Engineer' => 42000,
                'Project Manager' => 62000,
                'Finance Manager' => 48000,
            ],
            'skills' => [
                'Next.js' => 'Technical',
                'Node.js' => 'Technical',
                'PostgreSQL' => 'Technical',
                'Shopify' => 'Technical',
                'Figma' => 'Creative',
                'Brand Systems' => 'Creative',
                'QA Automation' => 'Technical',
                'Retail Operations' => 'Operations',
            ],
            'employees' => [
                ['key' => 'sales_lead', 'name' => 'Win Thiri', 'department' => 'Sales', 'role' => 'Account Director', 'capacity' => 'pm', 'salary' => 1900000, 'hours' => 160, 'skills' => ['Retail Operations' => 'expert']],
                ['key' => 'solution_architect', 'name' => 'Soe Myint Naing', 'department' => 'Delivery', 'role' => 'Solution Architect', 'capacity' => 'backend', 'salary' => 2300000, 'hours' => 160, 'skills' => ['Node.js' => 'expert', 'PostgreSQL' => 'expert']],
                ['key' => 'backend_one', 'name' => 'Paing Sithu', 'department' => 'Delivery', 'role' => 'Backend Engineer', 'capacity' => 'backend', 'salary' => 1700000, 'hours' => 160, 'skills' => ['Node.js' => 'expert', 'Shopify' => 'intermediate']],
                ['key' => 'backend_two', 'name' => 'Ye Htet Aung', 'department' => 'Delivery', 'role' => 'Backend Engineer', 'capacity' => 'backend', 'salary' => 1550000, 'hours' => 160, 'skills' => ['PostgreSQL' => 'intermediate', 'Next.js' => 'intermediate']],
                ['key' => 'frontend_one', 'name' => 'Thinzar Lwin', 'department' => 'Delivery', 'role' => 'Frontend Engineer', 'capacity' => 'frontend', 'salary' => 1620000, 'hours' => 160, 'skills' => ['Next.js' => 'expert', 'Figma' => 'intermediate']],
                ['key' => 'designer', 'name' => 'Phyu Phyu Kyaw', 'department' => 'Product Design', 'role' => 'Product Designer', 'capacity' => 'design', 'salary' => 1500000, 'hours' => 152, 'skills' => ['Figma' => 'expert', 'Brand Systems' => 'expert']],
                ['key' => 'qa_one', 'name' => 'Kaung Htet', 'department' => 'Delivery', 'role' => 'QA Engineer', 'capacity' => 'qa', 'salary' => 1050000, 'hours' => 160, 'skills' => ['QA Automation' => 'intermediate']],
                ['key' => 'project_manager', 'name' => 'Nandar Win', 'department' => 'Operations', 'role' => 'Project Manager', 'capacity' => 'pm', 'salary' => 1600000, 'hours' => 160, 'skills' => ['Retail Operations' => 'expert']],
                ['key' => 'finance_manager', 'name' => 'Hnin Yu Mon', 'department' => 'Finance', 'role' => 'Finance Manager', 'capacity' => 'pm', 'salary' => 1300000, 'hours' => 152, 'skills' => ['Retail Operations' => 'intermediate']],
            ],
            'users' => [
                ['key' => 'admin', 'employee' => 'sales_lead', 'first_name' => 'Win', 'last_name' => 'Thiri', 'email' => 'admin@mandalaystudio.demo', 'role' => 'Admin'],
                ['key' => 'sales', 'employee' => 'sales_lead', 'first_name' => 'Sales', 'last_name' => 'Mandalay', 'email' => 'sales@mandalaystudio.demo', 'role' => 'Sales'],
                ['key' => 'delivery', 'employee' => 'project_manager', 'first_name' => 'Delivery', 'last_name' => 'Mandalay', 'email' => 'delivery@mandalaystudio.demo', 'role' => 'Delivery'],
            ],
            'settings' => ['overhead_percentage' => 20, 'buffer_percentage' => 10, 'yearly_fixed_cost' => 168000000, 'employer_tax_percentage' => 7, 'benefits_percentage' => 10, 'cost_to_bill_ratio' => 0.44, 'fallback_hourly_cost' => 28000],
            'overheads' => [
                ['category' => 'Studio Rent', 'description' => 'Downtown Mandalay design studio', 'monthly_cost' => 7800000],
                ['category' => 'Production Tools', 'description' => 'Design, ecommerce, testing, and analytics tools', 'monthly_cost' => 4100000],
                ['category' => 'Photo Production', 'description' => 'Retail campaign shoot support', 'monthly_cost' => 2500000, 'effective_month' => 4, 'effective_year' => 2026],
            ],
            'deals' => $this->mandalayDeals(),
        ];
    }

    private function tokyoProductLab(): array
    {
        return [
            'name' => 'Tokyo Product Lab',
            'slug' => 'tokyo-product-lab',
            'currency' => 'JPY',
            'role_rates' => [
                'Account Director' => 14000,
                'Solution Architect' => 15000,
                'Backend Engineer' => 12500,
                'Frontend Engineer' => 11500,
                'Product Designer' => 10800,
                'QA Engineer' => 8200,
                'Project Manager' => 11800,
                'Finance Manager' => 9000,
            ],
            'skills' => [
                'Rails' => 'Technical',
                'React' => 'Technical',
                'PostgreSQL' => 'Technical',
                'AWS' => 'Technical',
                'Figma' => 'Creative',
                'QA Automation' => 'Technical',
                'Bilingual Delivery' => 'Management',
                'Logistics Integrations' => 'Operations',
            ],
            'employees' => [
                ['key' => 'sales_lead', 'name' => 'Haruka Sato', 'department' => 'Sales', 'role' => 'Account Director', 'capacity' => 'pm', 'salary' => 720000, 'hours' => 160, 'skills' => ['Bilingual Delivery' => 'expert']],
                ['key' => 'solution_architect', 'name' => 'Daichi Tanaka', 'department' => 'Delivery', 'role' => 'Solution Architect', 'capacity' => 'backend', 'salary' => 880000, 'hours' => 160, 'skills' => ['Rails' => 'expert', 'PostgreSQL' => 'expert', 'AWS' => 'expert']],
                ['key' => 'backend_one', 'name' => 'Kenji Mori', 'department' => 'Delivery', 'role' => 'Backend Engineer', 'capacity' => 'backend', 'salary' => 690000, 'hours' => 160, 'skills' => ['Rails' => 'expert', 'Logistics Integrations' => 'intermediate']],
                ['key' => 'backend_two', 'name' => 'Yuto Kobayashi', 'department' => 'Delivery', 'role' => 'Backend Engineer', 'capacity' => 'backend', 'salary' => 640000, 'hours' => 160, 'skills' => ['PostgreSQL' => 'intermediate', 'AWS' => 'intermediate']],
                ['key' => 'frontend_one', 'name' => 'Aiko Nakamura', 'department' => 'Delivery', 'role' => 'Frontend Engineer', 'capacity' => 'frontend', 'salary' => 630000, 'hours' => 160, 'skills' => ['React' => 'expert', 'Figma' => 'intermediate']],
                ['key' => 'designer', 'name' => 'Mika Ito', 'department' => 'Product Design', 'role' => 'Product Designer', 'capacity' => 'design', 'salary' => 610000, 'hours' => 152, 'skills' => ['Figma' => 'expert', 'Bilingual Delivery' => 'intermediate']],
                ['key' => 'qa_one', 'name' => 'Riku Yamamoto', 'department' => 'Delivery', 'role' => 'QA Engineer', 'capacity' => 'qa', 'salary' => 470000, 'hours' => 160, 'skills' => ['QA Automation' => 'expert']],
                ['key' => 'project_manager', 'name' => 'Yui Watanabe', 'department' => 'Operations', 'role' => 'Project Manager', 'capacity' => 'pm', 'salary' => 660000, 'hours' => 160, 'skills' => ['Bilingual Delivery' => 'expert', 'Logistics Integrations' => 'intermediate']],
                ['key' => 'finance_manager', 'name' => 'Naoko Suzuki', 'department' => 'Finance', 'role' => 'Finance Manager', 'capacity' => 'pm', 'salary' => 560000, 'hours' => 152, 'skills' => ['Bilingual Delivery' => 'intermediate']],
            ],
            'users' => [
                ['key' => 'admin', 'employee' => 'sales_lead', 'first_name' => 'Haruka', 'last_name' => 'Sato', 'email' => 'admin@tokyolab.demo', 'role' => 'Admin'],
                ['key' => 'sales', 'employee' => 'sales_lead', 'first_name' => 'Sales', 'last_name' => 'Tokyo', 'email' => 'sales@tokyolab.demo', 'role' => 'Sales'],
                ['key' => 'delivery', 'employee' => 'project_manager', 'first_name' => 'Delivery', 'last_name' => 'Tokyo', 'email' => 'delivery@tokyolab.demo', 'role' => 'Delivery'],
            ],
            'settings' => ['overhead_percentage' => 24, 'buffer_percentage' => 8, 'yearly_fixed_cost' => 118800000, 'employer_tax_percentage' => 9, 'benefits_percentage' => 13, 'cost_to_bill_ratio' => 0.48, 'fallback_hourly_cost' => 6500],
            'overheads' => [
                ['category' => 'Tokyo Office', 'description' => 'Shibuya workspace and utilities', 'monthly_cost' => 3900000],
                ['category' => 'Cloud and Security', 'description' => 'AWS, security scanning, SSO, and monitoring', 'monthly_cost' => 2100000],
                ['category' => 'Client Workshops', 'description' => 'Bilingual workshop support and travel', 'monthly_cost' => 850000, 'effective_month' => 5, 'effective_year' => 2026],
            ],
            'deals' => $this->tokyoDeals(),
        ];
    }

    private function yangonDeals(): array
    {
        return [
            $this->dealRow('YDW-CON-2026-001', 'YDW-PRJ-101', 'Merchant Wallet Reconciliation Portal', 'AyarPay Services', 186000000, 1640, 'won', 100, '2026-02-18', [
                'backend_one' => 310, 'backend_two' => 220, 'frontend_one' => 280, 'designer' => 120, 'qa_one' => 160, 'project_manager' => 140,
            ], 'Active', 'On Track', [
                ['key' => 'discovery', 'name' => 'Discovery and data mapping', 'due_date' => '2026-03-15', 'amount' => 42000000, 'status' => 'Completed'],
                ['key' => 'uat', 'name' => 'UAT pilot with finance team', 'due_date' => '2026-05-05', 'amount' => 62000000, 'status' => 'In Progress'],
                ['key' => 'launch', 'name' => 'Production launch', 'due_date' => '2026-07-18', 'amount' => 82000000, 'status' => 'Pending'],
            ], [
                ['number' => 'YDW-INV-2026-001', 'milestone' => 'discovery', 'issue_date' => '2026-03-16', 'due_date' => '2026-03-30', 'amount' => 42000000, 'tax' => 2100000, 'status' => 'Paid', 'paid_at' => '2026-03-29', 'notes' => 'Discovery milestone paid.'],
                ['number' => 'YDW-INV-2026-002', 'milestone' => 'uat', 'issue_date' => '2026-05-06', 'due_date' => '2026-05-20', 'amount' => 62000000, 'tax' => 3100000, 'status' => 'Pending', 'notes' => 'UAT milestone submitted to client.'],
            ]),
            $this->dealRow('YDW-CON-2026-002', 'YDW-PRJ-102', 'Hospital Queue and Appointment System', 'Shwe Taw Hospital Group', 128000000, 1180, 'won', 100, '2026-01-09', [
                'solution_architect' => 180, 'backend_one' => 260, 'frontend_one' => 210, 'designer' => 100, 'qa_one' => 150, 'project_manager' => 120,
            ], 'Completed', 'Completed', [
                ['key' => 'build', 'name' => 'Core booking platform', 'due_date' => '2026-02-20', 'amount' => 52000000, 'status' => 'Completed'],
                ['key' => 'rollout', 'name' => 'Branch rollout and staff training', 'due_date' => '2026-04-25', 'amount' => 76000000, 'status' => 'Completed'],
            ], [
                ['number' => 'YDW-INV-2026-003', 'milestone' => 'build', 'issue_date' => '2026-02-21', 'due_date' => '2026-03-07', 'amount' => 52000000, 'tax' => 2600000, 'status' => 'Paid', 'paid_at' => '2026-03-05', 'notes' => 'Core build completed.'],
                ['number' => 'YDW-INV-2026-004', 'milestone' => 'rollout', 'issue_date' => '2026-04-26', 'due_date' => '2026-05-10', 'amount' => 76000000, 'tax' => 3800000, 'status' => 'Paid', 'paid_at' => '2026-05-08', 'notes' => 'Rollout accepted.'],
            ]),
            $this->pipelineDeal('Cross-Border Remittance Risk Dashboard', 'Mingalar Money Transfer', 242000000, 2100, 'negotiation', 75, '2026-06-15', 'partner', ['backend_one' => 220, 'frontend_one' => 160, 'designer' => 80, 'project_manager' => 80]),
            $this->pipelineDeal('Tea Exporter B2B Ordering Portal', 'Golden Leaf Export', 86000000, 780, 'qualified', 55, '2026-06-04', 'referral', []),
            $this->pipelineDeal('Insurance Claims Mobile Back Office', 'Tharaphu Insurance', 156000000, 1320, 'qualified', 35, '2026-07-22', 'inbound', []),
            $this->lostDeal('Legacy ERP Rescue Assessment', 'Irrawaddy Distribution', 48000000, 360, '2026-04-12', 'Client deferred until Q4 after board review.'),
        ];
    }

    private function mandalayDeals(): array
    {
        return [
            $this->dealRow('MSC-CON-2026-001', 'MSC-PRJ-101', 'Omnichannel Retail Commerce Relaunch', 'Royal Jade Retail', 98000000, 1040, 'won', 100, '2026-02-05', [
                'backend_one' => 230, 'backend_two' => 150, 'frontend_one' => 250, 'designer' => 160, 'qa_one' => 110, 'project_manager' => 100,
            ], 'Active', 'At Risk', [
                ['key' => 'design', 'name' => 'Brand and UX system', 'due_date' => '2026-03-08', 'amount' => 28000000, 'status' => 'Completed'],
                ['key' => 'commerce', 'name' => 'Commerce build and integrations', 'due_date' => '2026-05-18', 'amount' => 44000000, 'status' => 'In Progress'],
                ['key' => 'launch', 'name' => 'Launch support', 'due_date' => '2026-06-30', 'amount' => 26000000, 'status' => 'Pending'],
            ], [
                ['number' => 'MSC-INV-2026-001', 'milestone' => 'design', 'issue_date' => '2026-03-09', 'due_date' => '2026-03-23', 'amount' => 28000000, 'tax' => 1400000, 'status' => 'Paid', 'paid_at' => '2026-03-21', 'notes' => 'Design system accepted.'],
                ['number' => 'MSC-INV-2026-002', 'milestone' => 'commerce', 'issue_date' => '2026-05-19', 'due_date' => '2026-06-02', 'amount' => 44000000, 'tax' => 2200000, 'status' => 'Pending', 'notes' => 'Commerce build milestone awaiting payment.'],
            ]),
            $this->dealRow('MSC-CON-2026-002', 'MSC-PRJ-102', 'Hotel Group Booking Microsites', 'Bagan Heritage Hotels', 62000000, 690, 'won', 100, '2026-01-18', [
                'backend_two' => 120, 'frontend_one' => 240, 'designer' => 120, 'qa_one' => 90, 'project_manager' => 70,
            ], 'Completed', 'Completed', [
                ['key' => 'prototype', 'name' => 'Prototype and booking flow', 'due_date' => '2026-02-12', 'amount' => 24000000, 'status' => 'Completed'],
                ['key' => 'sites', 'name' => 'Four property microsites', 'due_date' => '2026-04-10', 'amount' => 38000000, 'status' => 'Completed'],
            ], [
                ['number' => 'MSC-INV-2026-003', 'milestone' => 'prototype', 'issue_date' => '2026-02-13', 'due_date' => '2026-02-27', 'amount' => 24000000, 'tax' => 1200000, 'status' => 'Paid', 'paid_at' => '2026-02-25', 'notes' => 'Prototype paid.'],
                ['number' => 'MSC-INV-2026-004', 'milestone' => 'sites', 'issue_date' => '2026-04-11', 'due_date' => '2026-04-25', 'amount' => 38000000, 'tax' => 1900000, 'status' => 'Paid', 'paid_at' => '2026-04-23', 'notes' => 'Microsites delivered.'],
            ]),
            $this->pipelineDeal('Wholesale Inventory Mobile App', 'Zay Cho Market Cooperative', 72000000, 840, 'qualified', 60, '2026-06-20', 'event', []),
            $this->pipelineDeal('Tour Operator CRM and Quotation Tool', 'Upper Myanmar Journeys', 54000000, 620, 'qualified', 40, '2026-07-02', 'inbound', []),
            $this->pipelineDeal('Retail Loyalty Data Mart', 'Mandalay Mart', 118000000, 1250, 'negotiation', 70, '2026-06-10', 'referral', ['backend_one' => 160, 'frontend_one' => 100, 'project_manager' => 60]),
            $this->lostDeal('Food Delivery Campaign Microsite', 'Taste Mandalay', 26000000, 260, '2026-04-16', 'Lost to a lower-cost freelancer.'),
        ];
    }

    private function tokyoDeals(): array
    {
        return [
            $this->dealRow('TPL-CON-2026-001', 'TPL-PRJ-101', 'SaaS Customer Success Analytics', 'Kanda Cloud Systems', 24500000, 1480, 'won', 100, '2026-02-14', [
                'solution_architect' => 260, 'backend_one' => 280, 'frontend_one' => 260, 'designer' => 110, 'qa_one' => 150, 'project_manager' => 140,
            ], 'Active', 'On Track', [
                ['key' => 'alpha', 'name' => 'Data model and alpha dashboard', 'due_date' => '2026-03-28', 'amount' => 7200000, 'status' => 'Completed'],
                ['key' => 'beta', 'name' => 'Beta metrics and permissions', 'due_date' => '2026-05-22', 'amount' => 9300000, 'status' => 'In Progress'],
                ['key' => 'launch', 'name' => 'Launch and enablement', 'due_date' => '2026-07-12', 'amount' => 8000000, 'status' => 'Pending'],
            ], [
                ['number' => 'TPL-INV-2026-001', 'milestone' => 'alpha', 'issue_date' => '2026-03-29', 'due_date' => '2026-04-12', 'amount' => 7200000, 'tax' => 720000, 'status' => 'Paid', 'paid_at' => '2026-04-10', 'notes' => 'Alpha dashboard accepted.'],
                ['number' => 'TPL-INV-2026-002', 'milestone' => 'beta', 'issue_date' => '2026-05-23', 'due_date' => '2026-06-06', 'amount' => 9300000, 'tax' => 930000, 'status' => 'Pending', 'notes' => 'Beta milestone sent to client.'],
            ]),
            $this->dealRow('TPL-CON-2026-002', 'TPL-PRJ-102', 'Warehouse Picking Optimization MVP', 'Sumida Logistics', 16800000, 940, 'won', 100, '2026-01-22', [
                'backend_one' => 260, 'backend_two' => 140, 'frontend_one' => 160, 'designer' => 70, 'qa_one' => 100, 'project_manager' => 90,
            ], 'Completed', 'Completed', [
                ['key' => 'mvp', 'name' => 'MVP build and handheld workflow', 'due_date' => '2026-03-18', 'amount' => 9800000, 'status' => 'Completed'],
                ['key' => 'pilot', 'name' => 'Pilot rollout in Chiba warehouse', 'due_date' => '2026-04-28', 'amount' => 7000000, 'status' => 'Completed'],
            ], [
                ['number' => 'TPL-INV-2026-003', 'milestone' => 'mvp', 'issue_date' => '2026-03-19', 'due_date' => '2026-04-02', 'amount' => 9800000, 'tax' => 980000, 'status' => 'Paid', 'paid_at' => '2026-03-31', 'notes' => 'MVP accepted.'],
                ['number' => 'TPL-INV-2026-004', 'milestone' => 'pilot', 'issue_date' => '2026-04-29', 'due_date' => '2026-05-13', 'amount' => 7000000, 'tax' => 700000, 'status' => 'Paid', 'paid_at' => '2026-05-11', 'notes' => 'Pilot completed.'],
            ]),
            $this->pipelineDeal('Multilingual Partner Portal', 'Nihon Travel Partners', 13200000, 760, 'qualified', 50, '2026-06-18', 'partner', []),
            $this->pipelineDeal('Fintech Compliance Evidence Vault', 'Shinjuku Payments', 28600000, 1660, 'negotiation', 80, '2026-06-28', 'referral', ['solution_architect' => 220, 'backend_one' => 180, 'qa_one' => 120, 'project_manager' => 90]),
            $this->pipelineDeal('HR Onboarding Workflow Tool', 'Meguro People Ops', 9400000, 520, 'qualified', 35, '2026-07-15', 'inbound', []),
            $this->lostDeal('Event Ticketing Landing System', 'Tokyo Culture Week', 5200000, 300, '2026-04-07', 'Client paused the campaign after sponsor budget changed.'),
        ];
    }

    private function dealRow(
        string $contractNumber,
        string $projectNumber,
        string $name,
        string $client,
        float $budget,
        float $hours,
        string $status,
        int $probability,
        string $wonAt,
        array $assignments,
        string $contractStatus,
        string $projectStatus,
        array $milestones,
        array $invoices
    ): array {
        return [
            'name' => $name,
            'client' => $client,
            'contact' => ['name' => 'Nandar Client', 'email' => Str::slug($client, '.').'@example.com', 'phone' => '+95 9 555 0101'],
            'estimated_value' => $budget,
            'client_budget' => $budget,
            'workload_hours' => $hours,
            'timeline_months' => 5,
            'status' => $status,
            'probability' => $probability,
            'expected_close' => $wonAt,
            'won_at' => $wonAt,
            'lead_source' => 'referral',
            'target_margin' => 32,
            'description' => "{$name}: discovery, UX, secure backend workflows, reporting dashboard, QA, deployment, and staff handover.",
            'ghost_roles' => [
                ['role_type' => 'backend', 'quantity' => 2],
                ['role_type' => 'frontend', 'quantity' => 1],
                ['role_type' => 'design', 'quantity' => 1],
                ['role_type' => 'qa', 'quantity' => 1],
                ['role_type' => 'pm', 'quantity' => 1],
            ],
            'assignments' => $assignments,
            'resources' => [
                ['role' => 'Project Manager', 'role_code' => 'pm', 'feature' => 'Discovery, planning, client governance', 'hours' => 90],
                ['role' => 'Product Designer', 'role_code' => 'design', 'feature' => 'UX flows and design system', 'hours' => 120],
                ['role' => 'Backend Engineer', 'role_code' => 'backend', 'feature' => 'API, data model, integrations, auth', 'hours' => 430],
                ['role' => 'Frontend Engineer', 'role_code' => 'frontend', 'feature' => 'Responsive dashboard and client portal', 'hours' => 300],
                ['role' => 'QA Engineer', 'role_code' => 'qa', 'feature' => 'Regression testing and UAT support', 'hours' => 130],
            ],
            'overheads' => [
                ['name' => 'Cloud staging environment', 'cost' => round($budget * 0.018, 2)],
                ['name' => 'Client workshop and training materials', 'cost' => round($budget * 0.012, 2)],
            ],
            'win_reason' => 'Client selected the team for domain experience and clear delivery plan.',
            'delivery' => [
                'contract_number' => $contractNumber,
                'project_number' => $projectNumber,
                'contract_status' => $contractStatus,
                'project_status' => $projectStatus,
                'start_date' => Carbon::parse($wonAt)->addDays(7)->toDateString(),
                'end_date' => $contractStatus === 'Completed' ? Carbon::parse($wonAt)->addMonths(4)->toDateString() : null,
                'contract_notes' => 'Commercial terms include milestone invoicing, weekly status reporting, and UAT acceptance.',
                'milestones' => $milestones,
                'invoices' => $invoices,
                'time_entries' => $this->timeEntriesForDelivery($assignments, $contractStatus === 'Completed'),
            ],
        ];
    }

    private function pipelineDeal(
        string $name,
        string $client,
        float $budget,
        float $hours,
        string $status,
        int $probability,
        string $expectedClose,
        string $source,
        array $assignments
    ): array {
        return [
            'name' => $name,
            'client' => $client,
            'contact' => ['name' => 'Prospect Sponsor', 'email' => Str::slug($client, '.').'@example.com', 'phone' => '+95 9 777 0202'],
            'estimated_value' => $budget,
            'client_budget' => $budget,
            'workload_hours' => $hours,
            'timeline_months' => 4,
            'status' => $status,
            'probability' => $probability,
            'expected_close' => $expectedClose,
            'lead_source' => $source,
            'target_margin' => 30,
            'description' => "{$name}: active sales opportunity with scoped workshops, cost estimate, staffing plan, and commercial risk tracking.",
            'ghost_roles' => [
                ['role_type' => 'backend', 'quantity' => 1],
                ['role_type' => 'frontend', 'quantity' => 1],
                ['role_type' => 'design', 'quantity' => 1],
                ['role_type' => 'pm', 'quantity' => 1],
            ],
            'assignments' => $assignments,
            'resources' => [
                ['role' => 'Project Manager', 'role_code' => 'pm', 'feature' => 'Discovery and delivery planning', 'hours' => 55],
                ['role' => 'Backend Engineer', 'role_code' => 'backend', 'feature' => 'Integration and data services', 'hours' => 220],
                ['role' => 'Frontend Engineer', 'role_code' => 'frontend', 'feature' => 'Portal UI and workflow screens', 'hours' => 180],
                ['role' => 'Product Designer', 'role_code' => 'design', 'feature' => 'Prototype and usability review', 'hours' => 70],
            ],
            'overheads' => [
                ['name' => 'Solution workshop', 'cost' => round($budget * 0.01, 2)],
                ['name' => 'Prototype tooling', 'cost' => round($budget * 0.008, 2)],
            ],
        ];
    }

    private function lostDeal(string $name, string $client, float $budget, float $hours, string $lostAt, string $reason): array
    {
        $deal = $this->pipelineDeal($name, $client, $budget, $hours, 'lost', 0, $lostAt, 'cold_outreach', []);
        $deal['lost_at'] = $lostAt;
        $deal['loss_reason'] = $reason;

        return $deal;
    }

    private function timeEntriesForDelivery(array $assignments, bool $completed): array
    {
        $dates = $completed
            ? ['2026-02-06', '2026-03-14', '2026-04-18']
            : ['2026-03-10', '2026-04-12', '2026-05-08'];

        $entries = [];
        foreach ($assignments as $employeeKey => $hours) {
            $entries[] = ['employee' => $employeeKey, 'task' => 'Sprint implementation and review', 'date' => $dates[0], 'hours' => min(42, max(8, round($hours * 0.22))), 'status' => 'Approved'];
            $entries[] = ['employee' => $employeeKey, 'task' => 'Feature completion and QA fixes', 'date' => $dates[1], 'hours' => min(46, max(6, round($hours * 0.18))), 'status' => 'Approved'];
            $entries[] = ['employee' => $employeeKey, 'task' => $completed ? 'Launch support and handover' : 'Assigned work for next sprint', 'date' => $dates[2], 'hours' => min(32, max(4, round($hours * 0.12))), 'status' => $completed ? 'Approved' : 'Draft'];
        }

        if (! $completed) {
            $entries[] = ['employee' => array_key_first($assignments), 'task' => 'Client requested reporting adjustment', 'date' => '2026-05-09', 'hours' => 6, 'status' => 'Pending'];
        }

        return $entries;
    }
}
