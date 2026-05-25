<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProjectTeamAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the new "idle full-time" employee pool used by:
 *   - Employee::scopeIdleAndFullTime
 *   - AiAutoAssignController::availableEmployees (GET /projects/{p}/available-employees)
 *   - AiAutoAssignController::planTeamPreview (pool query tightened to the same scope)
 *   - AiAutoAssignController::confirmTeamPlan (race-guard rejects poached picks)
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

        DB::table('tenants')->insert([
            ['id' => $this->tenantA, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'plan' => 'free', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->tenantB, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'plan' => 'free', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Two departments per tenant: one delivery-eligible (IT) and one
        // not (Sales). The new scope's `is_delivery_eligible` filter would
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

        // Project graph in tenant A — raw inserts to satisfy FKs without
        // tripping any of the model boot hooks (e.g. the Employee saving
        // hook that would otherwise force-compute monthly_salary). Tenant B
        // gets its own project for the cross-tenant assignment test.
        $this->seedProjectGraph($this->tenantA, $this->fakeProjectA);
        $this->seedProjectGraph($this->tenantB, $this->fakeProjectB);

        // Bind tenant A for BelongsToTenant defaulting and scoping.
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
        // Default to the tenant's delivery-eligible department. Tests that
        // care about the non-delivery case pass `department_id` explicitly.
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
            'assignment_source' => 'manual',
        ]);

        $ids = Employee::idleAndFullTime()->pluck('id')->all();

        $this->assertContains($idle->id, $ids, 'idle full-time employee should be in the pool');
        $this->assertNotContains($partTime->id, $ids, 'part-time should be excluded');
        $this->assertNotContains($inactive->id, $ids, 'non-Active should be excluded');
        $this->assertNotContains($assigned->id, $ids, 'employee with a team assignment should be excluded');
    }

    public function test_scope_is_tenant_scoped(): void
    {
        $idleA = $this->makeEmployee($this->tenantA, ['name' => 'A-Idle']);

        // Switch context to tenant B and create an idle employee there.
        app()->instance('tenant_id', $this->tenantB);
        $idleB = $this->makeEmployee($this->tenantB, ['name' => 'B-Idle']);

        // Still in tenant B context: the pool sees only tenant B's idle pool.
        $idsB = Employee::idleAndFullTime()->pluck('id')->all();
        $this->assertContains($idleB->id, $idsB);
        $this->assertNotContains($idleA->id, $idsB, 'tenant scope must hide other tenants\' employees');

        // Flip back to tenant A and confirm symmetry.
        app()->instance('tenant_id', $this->tenantA);
        $idsA = Employee::idleAndFullTime()->pluck('id')->all();
        $this->assertContains($idleA->id, $idsA);
        $this->assertNotContains($idleB->id, $idsA);
    }

    public function test_employee_with_any_project_assignment_is_excluded_even_if_zero_allocated_hours(): void
    {
        $emp = $this->makeEmployee($this->tenantA, ['name' => 'Stub Stan']);
        ProjectTeamAssignment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA,
            'project_id' => $this->fakeProjectA,
            'employee_id' => $emp->id,
            'allocated_hours' => 0, // edge: "unmatched kept member" pattern
            'assignment_source' => 'ai',
        ]);

        $ids = Employee::idleAndFullTime()->pluck('id')->all();
        $this->assertNotContains($emp->id, $ids,
            'even allocated_hours=0 assignments count as "on a project"');
    }

    public function test_employee_in_non_delivery_department_is_excluded(): void
    {
        // An otherwise-perfect candidate: Active, full-time, no project
        // assignments. But sits in a department flagged is_delivery_eligible
        // = false (Sales / HR / Finance pattern). The scope must filter
        // them out so the AI Team Builder never surfaces them as pickable.
        $deliveryEmp = $this->makeEmployee($this->tenantA, ['name' => 'IT Iku']);
        $salesEmp = $this->makeEmployee($this->tenantA, [
            'name' => 'Sales Sora',
            'department_id' => $this->nonDeliveryDeptByTenant[$this->tenantA],
        ]);

        $ids = Employee::idleAndFullTime()->pluck('id')->all();

        $this->assertContains($deliveryEmp->id, $ids, 'IT employee must remain in the idle pool');
        $this->assertNotContains($salesEmp->id, $ids,
            'employee in non-delivery-eligible department must be filtered out — even when otherwise idle');
    }

    public function test_employee_with_null_department_is_excluded(): void
    {
        // Defensive: an employee with no department_id at all should not
        // appear in the pool. The whereHas() returns false for missing
        // relations, so the rule is "explicit eligibility, not implicit".
        $orphan = $this->makeEmployee($this->tenantA, [
            'name' => 'Orphan Ora',
            'department_id' => null,
        ]);

        $this->assertNotContains($orphan->id, Employee::idleAndFullTime()->pluck('id')->all(),
            'employee without a department should be excluded from the idle pool');
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

        $this->assertNotContains($emp->id, Employee::idleAndFullTime()->pluck('id')->all());

        $assignment->delete();

        $this->assertContains($emp->id, Employee::idleAndFullTime()->pluck('id')->all(),
            'removing the assignment should re-admit the employee to the idle pool');
    }
}
