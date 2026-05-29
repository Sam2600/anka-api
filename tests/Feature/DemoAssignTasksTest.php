<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AiAutoAssignController;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectTaskAssignment;
use App\Models\ProjectTeamAssignment;
use App\Services\Scheduling\WorkingDayCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers the deterministic AI fallback path (AiAutoAssignController::demoAssignTasks)
 * after the integrated-walk rewrite. The walk now consults monthly_allocation
 * the same way the AI validator does — picking and date-placement happen in a
 * single pass so a candidate is rejected if the resulting calendar window
 * lands in a zero-month or exceeds their per-month budget.
 *
 * Tests invoke the private method via reflection to isolate the walk from the
 * xlsx-reading and API-key-checking infrastructure that wraps it.
 */
class DemoAssignTasksTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $projectId;

    private string $deliveryDept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->projectId = (string) Str::uuid();
        $this->deliveryDept = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId, 'name' => 'Tenant', 'slug' => 't', 'plan' => 'free',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('departments')->insert([
            'id' => $this->deliveryDept, 'tenant_id' => $this->tenantId, 'name' => 'IT',
            'headcount' => 0, 'is_delivery_eligible' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $dealId = (string) Str::uuid();
        $contractId = (string) Str::uuid();
        DB::table('deals')->insert([
            'id' => $dealId, 'tenant_id' => $this->tenantId, 'name' => 'Deal',
            'timeline_months' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contracts')->insert([
            'id' => $contractId, 'tenant_id' => $this->tenantId, 'deal_id' => $dealId,
            'contract_number' => 'CON-test', 'client' => 'Acme', 'total_value' => 100000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('projects')->insert([
            'id' => $this->projectId, 'tenant_id' => $this->tenantId, 'contract_id' => $contractId,
            'project_number' => 'PRJ-test', 'name' => 'BCMM chat app', 'client' => 'Acme',
            'start_date' => '2026-06-01', 'end_date' => '2026-08-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->instance('tenant_id', $this->tenantId);
    }

    private function makeEmployee(string $name, string $capacityRole, string $rankCode, array $monthlyAllocation): Employee
    {
        // Reuse the rank row if it already exists for this tenant — ranks
        // typically have a (tenant_id, code) uniqueness constraint, so we
        // must look up before insert rather than insertOrIgnore (which would
        // leave $rankId pointing at a non-existent row).
        $existingRank = DB::table('ranks')
            ->where('tenant_id', $this->tenantId)
            ->where('code', $rankCode)
            ->first();
        if ($existingRank) {
            $rankId = $existingRank->id;
        } else {
            $rankId = (string) Str::uuid();
            $rankLevel = ['Junior' => 10, 'Mid' => 20, 'Senior' => 30, 'Lead' => 40][$rankCode] ?? 20;
            DB::table('ranks')->insert([
                'id' => $rankId, 'tenant_id' => $this->tenantId,
                'code' => $rankCode, 'name' => $rankCode, 'level' => $rankLevel,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $emp = Employee::withoutEvents(fn () => Employee::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'department_id' => $this->deliveryDept,
            'rank_id' => $rankId,
            'name' => $name,
            'status' => 'Active',
            'workable_hours' => 160,
            'basic_salary' => 200000,
            'allowance' => 0,
            'capacity_role' => $capacityRole,
        ]));

        // Engagement budget: workable_hours × Σ(allocation)
        $totalBudget = 160 * array_sum($monthlyAllocation);
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'employee_id' => $emp->id,
            'allocated_hours' => $totalBudget,
            'monthly_allocation' => $monthlyAllocation,
            'team_start_date' => '2026-06-01',
            'team_end_date' => '2026-08-31',
            'assignment_source' => 'manual',
        ]);

        return $emp;
    }

    private function callDemoAssignTasks(array $tasks): array
    {
        $controller = new AiAutoAssignController;
        $reflection = new ReflectionMethod($controller, 'demoAssignTasks');
        $reflection->setAccessible(true);

        $project = Project::with(['contract.deal', 'teamAssignments.employee.rank', 'teamAssignments.employee.capacityRole'])
            ->find($this->projectId);
        $teamAssignments = $project->teamAssignments;

        $windowStart = Carbon::parse('2026-06-01');
        $effectiveEnd = Carbon::parse('2026-08-31');
        $calendar = new WorkingDayCalendar;

        $activePhases = [
            ['code' => 'basic_doc',   'name' => 'Basic doc',   'order' => 2],
            ['code' => 'development', 'name' => 'Development', 'order' => 5],
        ];

        $reflection->invoke(
            $controller, $project, $tasks, $activePhases, $teamAssignments,
            $this->tenantId, $windowStart, $effectiveEnd, $calendar,
        );

        // Read back what was persisted.
        return ProjectTaskAssignment::with('phaseAssignments')
            ->where('project_id', $this->projectId)
            ->get()
            ->map(fn ($row) => [
                'row_no' => $row->row_no,
                'phases' => $row->phaseAssignments->map(fn ($p) => [
                    'code' => $p->phase_code,
                    'assignee_id' => $p->assignee_id,
                    'planned_start' => optional($p->planned_start)->toDateString(),
                    'planned_end' => optional($p->planned_end)->toDateString(),
                ])->all(),
            ])->all();
    }

    public function test_walk_avoids_zero_month_assignee(): void
    {
        // Frontend is off in August (zero-month). PM and Backend are full all 3 months.
        $pm = $this->makeEmployee('Aung PM', 'pm', 'Senior', [1.0, 1.0, 1.0]);
        $be = $this->makeEmployee('Bo Backend', 'backend', 'Mid', [1.0, 1.0, 1.0]);
        $fe = $this->makeEmployee('Fei Frontend', 'frontend', 'Mid', [1.0, 1.0, 0.0]);

        // Task placed so basic_doc lands in June and development gets pushed
        // into August (backend has earlier capacity but is consumed; the walk
        // should still keep Frontend OUT of August work).
        // To force August scheduling we use a heavy development phase.
        $tasks = [[
            'row_no' => 1, 'difficulty' => '普通', 'function_name' => 'Login',
            'function_id' => 'F-1', 'total_hours' => 328.0,
            'phases' => [
                ['code' => 'basic_doc',   'name' => 'Basic doc',   'hours' => 8.0,   'order' => 2],
                ['code' => 'development', 'name' => 'Development', 'hours' => 320.0, 'order' => 5], // ~40 working days @ 8h/d
            ],
        ]];

        $result = $this->callDemoAssignTasks($tasks);

        $this->assertNotEmpty($result, 'demo should produce assignments');
        $devPhase = collect($result[0]['phases'])->firstWhere('code', 'development');
        $this->assertNotNull($devPhase, 'development phase should be assigned');

        // Development is large enough to span Jul-Aug. With Frontend off in
        // August, the walk should pick Backend instead (or split-but-walk
        // currently doesn't split tasks across people, so it must be Backend).
        $this->assertSame($be->id, $devPhase['assignee_id'],
            'development that spans into August must go to backend (not frontend off in Aug)');
    }

    public function test_legacy_member_without_monthly_allocation_still_assignable(): void
    {
        // Member with NULL monthly_allocation — legacy data shape. Walk should
        // fall through to total-cap only, no zero-month rule (nothing to check).
        $pm = $this->makeEmployee('Aung PM', 'pm', 'Senior', [1.0, 1.0, 1.0]);
        $legacyBe = Employee::withoutEvents(fn () => Employee::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'department_id' => $this->deliveryDept,
            'rank_id' => $pm->rank_id, // reuse the rank for brevity
            'name' => 'Legacy Backend',
            'status' => 'Active',
            'workable_hours' => 160,
            'basic_salary' => 200000,
            'allowance' => 0,
            'capacity_role' => 'backend',
        ]));
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'employee_id' => $legacyBe->id,
            'allocated_hours' => 480,
            'monthly_allocation' => null, // ← legacy
            'team_start_date' => null,
            'team_end_date' => null,
            'assignment_source' => 'manual',
        ]);

        $tasks = [[
            'row_no' => 1, 'difficulty' => '普通', 'function_name' => 'Login',
            'function_id' => 'F-1', 'total_hours' => 24.0,
            'phases' => [
                ['code' => 'basic_doc',   'name' => 'Basic doc',   'hours' => 8.0,  'order' => 2],
                ['code' => 'development', 'name' => 'Development', 'hours' => 16.0, 'order' => 5],
            ],
        ]];

        $result = $this->callDemoAssignTasks($tasks);

        $this->assertNotEmpty($result);
        $devPhase = collect($result[0]['phases'])->firstWhere('code', 'development');
        $this->assertSame($legacyBe->id, $devPhase['assignee_id'],
            'legacy member (NULL monthly_allocation) must remain assignable for development');
    }

    public function test_walk_respects_monthly_budget_when_alternative_exists(): void
    {
        // Two backends: Mid is half-time June (80h budget), Senior is full
        // (160h budget). A 120h dev phase in June must NOT go to Mid because
        // it would exceed Mid's 80h June budget; it should go to Senior.
        $pm = $this->makeEmployee('Aung PM', 'pm', 'Senior', [1.0, 1.0, 1.0]);
        $midBe = $this->makeEmployee('Mid Backend', 'backend', 'Mid', [0.5, 1.0, 1.0]);
        $srBe = $this->makeEmployee('Senior Backend', 'backend', 'Senior', [1.0, 1.0, 1.0]);

        $tasks = [[
            'row_no' => 1, 'difficulty' => '普通', 'function_name' => 'Login',
            'function_id' => 'F-1', 'total_hours' => 128.0,
            'phases' => [
                ['code' => 'basic_doc',   'name' => 'Basic doc',   'hours' => 8.0,   'order' => 2],
                ['code' => 'development', 'name' => 'Development', 'hours' => 120.0, 'order' => 5], // 15 days @ 8h/d
            ],
        ]];

        $result = $this->callDemoAssignTasks($tasks);

        $devPhase = collect($result[0]['phases'])->firstWhere('code', 'development');
        $this->assertNotNull($devPhase);
        $this->assertSame($srBe->id, $devPhase['assignee_id'],
            '120h June dev must go to the Senior (160h budget) not the Mid (80h budget)');
    }
}
