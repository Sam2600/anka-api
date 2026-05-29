<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProjectTeamAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the time-bounded "idle" employee pool used by:
 *   - Employee::scopeIdleForRange
 *   - AiAutoAssignController::availableEmployees (GET /projects/{p}/available-employees)
 *   - AiAutoAssignController::planTeamPreview (same scope, with kept-employee exclusion)
 *   - AiAutoAssignController::confirmTeamPlan (race guard mirrors the same overlap rule)
 *
 * We skip building the full Deal → Contract → Project graph: the scope only
 * needs `employees` + `project_team_assignments` rows. Foreign keys are
 * disabled per-test so we can attach a team assignment to an arbitrary
 * project UUID without spinning up the upstream tables.
 */
class IdleEmployeePoolTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantA;

    private string $tenantB;

    private string $fakeProjectA;

    private string $fakeProjectB;

    private Carbon $windowStart;

    private Carbon $windowEnd;

    /** @var array<string, string> tenant_id => delivery-eligible dept_id */
    private array $deliveryDeptByTenant = [];

    /** @var array<string, string> tenant_id => non-delivery dept_id */
    private array $nonDeliveryDeptByTenant = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = (string) Str::uuid();
        $this->tenantB = (string) Str::uuid();
        $this->fakeProjectA = (string) Str::uuid();
        $this->fakeProjectB = (string) Str::uuid();

        // Default query window used by tests that don't care about dates.
        // Anchored on a fixed calendar date so timing flakes don't bite.
        $this->windowStart = Carbon::parse('2026-09-01');
        $this->windowEnd = Carbon::parse('2026-12-31');

        DB::table('tenants')->insert([
            ['id' => $this->tenantA, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'plan' => 'free', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->tenantB, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'plan' => 'free', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Two departments per tenant: one delivery-eligible (IT) and one
        // not (Sales). The scope's `is_delivery_eligible` filter would
        // exclude employees in the latter even if they're otherwise idle.
        foreach ([$this->tenantA, $this->tenantB] as $tid) {
            $itId = (string) Str::uuid();
            $salesId = (string) Str::uuid();
            DB::table('departments')->insert([
                ['id' => $itId,    'tenant_id' => $tid, 'name' => 'IT',    'headcount' => 0, 'is_delivery_eligible' => true,  'created_at' => now(), 'updated_at' => now()],
                ['id' => $salesId, 'tenant_id' => $tid, 'name' => 'Sales', 'headcount' => 0, 'is_delivery_eligible' => false, 'created_at' => now(), 'updated_at' => now()],
            ]);
            $this->deliveryDeptByTenant[$tid] = $itId;
            $this->nonDeliveryDeptByTenant[$tid] = $salesId;
        }

        $this->seedProjectGraph($this->tenantA, $this->fakeProjectA);
        $this->seedProjectGraph($this->tenantB, $this->fakeProjectB);

        app()->instance('tenant_id', $this->tenantA);
    }

    private function seedProjectGraph(string $tenantId, string $projectId): void
    {
        $dealId = (string) Str::uuid();
        $contractId = (string) Str::uuid();
        DB::table('deals')->insert([
            'id' => $dealId, 'tenant_id' => $tenantId, 'name' => 'Deal', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contracts')->insert([
            'id' => $contractId, 'tenant_id' => $tenantId, 'deal_id' => $dealId,
            'contract_number' => 'CON-'.substr($contractId, 0, 8),
            'client' => 'Acme', 'total_value' => 100000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('projects')->insert([
            'id' => $projectId, 'tenant_id' => $tenantId, 'contract_id' => $contractId,
            'project_number' => 'PRJ-'.substr($projectId, 0, 8),
            'name' => 'Project', 'client' => 'Acme',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEmployee(string $tenantId, array $attrs = []): Employee
    {
        $defaultDept = $this->deliveryDeptByTenant[$tenantId] ?? null;

        return Employee::withoutEvents(fn () => Employee::create(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'department_id' => $defaultDept,
            'name' => 'Test '.Str::random(4),
            'status' => 'Active',
            'workable_hours' => 160,
            'basic_salary' => 200000,
            'allowance' => 0,
        ], $attrs)));
    }

    private function idlePool(?Carbon $start = null, ?Carbon $end = null): array
    {
        return Employee::idleForRange(
            $start ?? $this->windowStart,
            $end ?? $this->windowEnd,
        )->pluck('id')->all();
    }

    public function test_scope_returns_active_full_time_with_no_team_assignments(): void
    {
        $idle = $this->makeEmployee($this->tenantA, ['name' => 'Idle Ichiro']);
        $partTime = $this->makeEmployee($this->tenantA, ['name' => 'PartTime Pete', 'workable_hours' => 80]);
        $inactive = $this->makeEmployee($this->tenantA, ['name' => 'Inactive Ines', 'status' => 'On Leave']);
        $assigned = $this->makeEmployee($this->tenantA, ['name' => 'Busy Bob']);
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA,
            'project_id' => $this->fakeProjectA,
            'employee_id' => $assigned->id,
            'allocated_hours' => 160,
            // No team_start_date / team_end_date — treated as "active forever",
            // so this employee should be excluded for every window.
            'assignment_source' => 'manual',
        ]);

        $ids = $this->idlePool();

        $this->assertContains($idle->id, $ids, 'idle full-time employee should be in the pool');
        $this->assertNotContains($partTime->id, $ids, 'part-time should be excluded');
        $this->assertNotContains($inactive->id, $ids, 'non-Active should be excluded');
        $this->assertNotContains($assigned->id, $ids, 'employee with an open-ended team assignment should be excluded');
    }

    public function test_scope_is_tenant_scoped(): void
    {
        $idleA = $this->makeEmployee($this->tenantA, ['name' => 'A-Idle']);

        app()->instance('tenant_id', $this->tenantB);
        $idleB = $this->makeEmployee($this->tenantB, ['name' => 'B-Idle']);

        $idsB = $this->idlePool();
        $this->assertContains($idleB->id, $idsB);
        $this->assertNotContains($idleA->id, $idsB, 'tenant scope must hide other tenants\' employees');

        app()->instance('tenant_id', $this->tenantA);
        $idsA = $this->idlePool();
        $this->assertContains($idleA->id, $idsA);
        $this->assertNotContains($idleB->id, $idsA);
    }

    public function test_open_ended_assignment_excludes_employee_regardless_of_window(): void
    {
        $emp = $this->makeEmployee($this->tenantA, ['name' => 'Stub Stan']);
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA,
            'project_id' => $this->fakeProjectA,
            'employee_id' => $emp->id,
            'allocated_hours' => 0,
            'assignment_source' => 'ai',
        ]);

        $this->assertNotContains($emp->id, $this->idlePool(),
            'an assignment with NULL team_end_date should conflict with any window (defensive)');
    }

    public function test_employee_in_non_delivery_department_is_excluded(): void
    {
        $deliveryEmp = $this->makeEmployee($this->tenantA, ['name' => 'IT Iku']);
        $salesEmp = $this->makeEmployee($this->tenantA, [
            'name' => 'Sales Sora',
            'department_id' => $this->nonDeliveryDeptByTenant[$this->tenantA],
        ]);

        $ids = $this->idlePool();

        $this->assertContains($deliveryEmp->id, $ids, 'IT employee must remain in the idle pool');
        $this->assertNotContains($salesEmp->id, $ids,
            'employee in non-delivery-eligible department must be filtered out');
    }

    public function test_employee_with_null_department_is_excluded(): void
    {
        $orphan = $this->makeEmployee($this->tenantA, [
            'name' => 'Orphan Ora',
            'department_id' => null,
        ]);

        $this->assertNotContains($orphan->id, $this->idlePool(),
            'employee without a department should be excluded');
    }

    public function test_freshly_freed_employee_re_enters_pool(): void
    {
        $emp = $this->makeEmployee($this->tenantA, ['name' => 'Comeback Carl']);
        $assignment = ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA,
            'project_id' => $this->fakeProjectA,
            'employee_id' => $emp->id,
            'allocated_hours' => 160,
            'assignment_source' => 'manual',
        ]);

        $this->assertNotContains($emp->id, $this->idlePool());

        $assignment->delete();

        $this->assertContains($emp->id, $this->idlePool(),
            'removing the assignment should re-admit the employee to the idle pool');
    }

    // ── New overlap-semantics tests ───────────────────────────────────────────

    public function test_employee_on_finished_project_is_idle_for_future_window(): void
    {
        // The original bug scenario: engineer on Project A (Jan 20 – May 30)
        // should be available for Project C starting Aug 20.
        $emp = $this->makeEmployee($this->tenantA, ['name' => 'FreedUp Fumi']);
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA,
            'project_id' => $this->fakeProjectA,
            'employee_id' => $emp->id,
            'allocated_hours' => 704,
            'team_start_date' => '2026-01-20',
            'team_end_date' => '2026-05-30',
            'assignment_source' => 'ai',
        ]);

        $futureStart = Carbon::parse('2026-08-20');
        $futureEnd = Carbon::parse('2026-12-20');

        $this->assertContains($emp->id, $this->idlePool($futureStart, $futureEnd),
            'a finished engagement (ends May 30) must not block staffing in Aug-Dec');
    }

    public function test_employee_on_overlapping_project_is_not_idle(): void
    {
        // Project B-ish engagement Mar 1 – Jul 29; query window Jul 15 – Nov 15
        // overlaps on Jul 15-29, so this engineer must be excluded.
        $emp = $this->makeEmployee($this->tenantA, ['name' => 'Busy Brenda']);
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA,
            'project_id' => $this->fakeProjectA,
            'employee_id' => $emp->id,
            'allocated_hours' => 704,
            'team_start_date' => '2026-03-01',
            'team_end_date' => '2026-07-29',
            'assignment_source' => 'ai',
        ]);

        $queryStart = Carbon::parse('2026-07-15');
        $queryEnd = Carbon::parse('2026-11-15');

        $this->assertNotContains($emp->id, $this->idlePool($queryStart, $queryEnd),
            'engagement ending Jul 29 must block a window starting Jul 15');
    }

    public function test_assignment_touching_window_edges_blocks_employee(): void
    {
        // Edge case: engagement ends exactly on the query start date. Per
        // overlap rule (a <= d AND c <= b), this counts as an overlap and
        // the employee is blocked. Better safe than double-booked.
        $emp = $this->makeEmployee($this->tenantA, ['name' => 'Edgy Eiji']);
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA,
            'project_id' => $this->fakeProjectA,
            'employee_id' => $emp->id,
            'allocated_hours' => 160,
            'team_start_date' => '2026-06-01',
            'team_end_date' => '2026-09-01', // exactly on $windowStart
            'assignment_source' => 'ai',
        ]);

        $this->assertNotContains($emp->id, $this->idlePool(),
            'engagement ending exactly on the query start must still block (inclusive overlap)');
    }
}
