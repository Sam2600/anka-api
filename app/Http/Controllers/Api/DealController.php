<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Http\Resources\DealResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function index(Request $request)
    {
        $query = Deal::with(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = '%'.$request->search.'%';
            $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($search, $op) {
                $q->where('name', $op, $search)
                    ->orWhere('client', $op, $search);
            });
        }

        $perPage = min((int) ($request->per_page ?? 100), 500);

        return DealResource::collection($query->orderBy('created_at', 'desc')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'client' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'required|string|max:50',
            'status' => 'nullable|in:lead,qualified,negotiation,won',
            'expected_close_date' => 'nullable|date',
            'lead_source' => 'nullable|in:inbound,referral,cold_outreach,social,event,partner,other',
            'estimated_value' => 'nullable|numeric|min:0',
            'win_probability' => 'nullable|integer|min:0|max:100',
            'client_budget' => 'nullable|numeric|min:0',
            'timeline_months' => 'nullable|integer|min:1',
            'workload_hours' => 'nullable|numeric|min:0',
            'target_margin' => 'nullable|numeric|min:0|max:100',
            'ot_policy_model' => 'sometimes|nullable|in:customer_pays_per_hour,capped_then_customer_pays,absorbed_by_provider,no_overtime_allowed',
            'ot_rate_per_hour' => 'sometimes|nullable|numeric|min:0',
            'ot_included_hours_per_month' => 'sometimes|nullable|integer|min:0|max:744',
            'ot_notes' => 'sometimes|nullable|string|max:2000',
            'customer_support_obligations' => 'sometimes|nullable|string|max:2000',
            'out_of_scope_policy' => 'sometimes|nullable|string|max:2000',
            'working_hours' => 'sometimes|nullable|string|max:500',
            'testing_range' => 'sometimes|nullable|string|max:1000',
            'wizard_step' => 'sometimes|in:context,estimation,staffing,complete',
            'ghost_roles' => 'sometimes|array',
            'ghost_roles.*.role_type' => 'required|string|in:frontend,backend,pm,qa,design',
            'ghost_roles.*.quantity' => 'required|integer|min:1',
            'ghost_roles.*.months' => 'required|integer|min:1',
            'ghost_roles.*.avg_monthly_salary' => 'required|numeric|min:0',
            'ghost_roles.*.min_monthly_salary' => 'nullable|numeric|min:0',
            'ghost_roles.*.max_monthly_salary' => 'nullable|numeric|min:0',
            'hard_assignments' => 'sometimes|array',
            // Constrain employee_id to the active tenant: Rule::exists uses the
            // raw query builder (Eloquent global scopes don't apply), so without
            // the explicit where(tenant_id) a crafted request could attach
            // another tenant's employee id. whereNull(deleted_at) keeps
            // soft-deleted employees out of the candidate set too.
            'hard_assignments.*.employee_id' => [
                'required',
                'string',
                Rule::exists('employees', 'id')
                    ->where('tenant_id', app('tenant_id'))
                    ->whereNull('deleted_at'),
            ],
            'hard_assignments.*.allocated_hours' => 'required|numeric|min:0',
        ]);

        // Server-side capacity check: the staffing UI blocks over-allocation,
        // but a direct API request would otherwise sail past it.
        $this->assertCapacityFeasible($request, null);

        $deal = DB::transaction(function () use ($request) {
            $deal = Deal::create($request->except([
                'ghost_roles',
                'hard_assignments',
                'estimation_resources',
                'deal_overheads',
            ]));

            $this->replaceDealChildren($deal, $request);

            return $deal;
        });

        return new DealResource($deal->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']));
    }

    public function show(Deal $deal)
    {
        return new DealResource($deal->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']));
    }

    public function update(Request $request, Deal $deal)
    {
        // chg-011 Phase B-breaking: lock terms once contract drafting starts.
        // Reject writes to scope/budget/timeline/final_* when rank ∈ {A, S}.
        // Deal::lockViolations is the single source of truth.
        $lockErrors = $deal->lockViolations(array_keys($request->all()));
        if (! empty($lockErrors)) {
            throw ValidationException::withMessages($lockErrors);
        }

        // Status field-level rules: enforce forward-only state-machine when
        // the status changes. Only Estimation is allowed to flip C→B; the
        // contract-drafting service flips B→A and A→S internally. Other
        // transitions are 422.
        $newStatus = $request->input('status');
        if ($newStatus !== null && $newStatus !== $deal->status) {
            if (! $deal->canTransitionTo($newStatus)) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Invalid rank transition: {$deal->status} → {$newStatus}. "
                        . 'Forward-only (lead → qualified → negotiation → won); '
                        . 'no manual override; A→S only via signed contract.',
                    ],
                ]);
            }
            // Lifecycle guard: don't allow re-promoting a dropped deal.
            if ($deal->isDropped()) {
                throw ValidationException::withMessages([
                    'status' => ['Dropped deals cannot be re-promoted. Create a new deal instead.'],
                ]);
            }
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'client' => 'sometimes|required|string|max:255',
            'contact_name' => 'sometimes|required|string|max:255',
            'contact_email' => 'sometimes|required|email|max:255',
            'contact_phone' => 'sometimes|required|string|max:50',
            'status' => 'sometimes|in:lead,qualified,negotiation,won',
            'expected_close_date' => 'sometimes|nullable|date',
            'lead_source' => 'sometimes|nullable|in:inbound,referral,cold_outreach,social,event,partner,other',
            'estimated_value' => 'sometimes|nullable|numeric|min:0',
            'win_probability' => 'sometimes|nullable|integer|min:0|max:100',
            'client_budget' => 'sometimes|nullable|numeric|min:0',
            'timeline_months' => 'sometimes|nullable|integer|min:1',
            'workload_hours' => 'sometimes|nullable|numeric|min:0',
            'target_margin' => 'sometimes|nullable|numeric|min:0|max:100',
            'ot_policy_model' => 'sometimes|nullable|in:customer_pays_per_hour,capped_then_customer_pays,absorbed_by_provider,no_overtime_allowed',
            'ot_rate_per_hour' => 'sometimes|nullable|numeric|min:0',
            'ot_included_hours_per_month' => 'sometimes|nullable|integer|min:0|max:744',
            'ot_notes' => 'sometimes|nullable|string|max:2000',
            'customer_support_obligations' => 'sometimes|nullable|string|max:2000',
            'out_of_scope_policy' => 'sometimes|nullable|string|max:2000',
            'working_hours' => 'sometimes|nullable|string|max:500',
            'testing_range' => 'sometimes|nullable|string|max:1000',
            'final_monthly_fee' => 'sometimes|nullable|numeric|min:0',
            'final_installation_fee' => 'sometimes|nullable|numeric|min:0',
            'final_contract_months' => 'sometimes|nullable|integer|min:1',
            'final_ot_policy' => 'sometimes|nullable|string|max:2000',
            'final_support_hours_per_month' => 'sometimes|nullable|integer|min:0|max:744',
            'final_team_summary' => 'sometimes|nullable|string|max:2000',
            'final_currency' => 'sometimes|nullable|string|size:3',
            'final_confirmed_at' => 'sometimes|nullable|date|before_or_equal:now',
            'suggested_template_variant' => 'sometimes|nullable|in:cloud_backup,managed_hosting,engineer_dispatch',
            'wizard_step' => 'sometimes|in:context,estimation,staffing,complete',
            'ghost_roles' => 'sometimes|array',
            'ghost_roles.*.role_type' => 'required|string',
            'ghost_roles.*.quantity' => 'required|integer|min:1',
            'ghost_roles.*.months' => 'required|integer|min:1',
            'ghost_roles.*.avg_monthly_salary' => 'required|numeric|min:0',
            'ghost_roles.*.min_monthly_salary' => 'nullable|numeric|min:0',
            'ghost_roles.*.max_monthly_salary' => 'nullable|numeric|min:0',
            'hard_assignments' => 'sometimes|array',
            // Same tenant-scoped Rule::exists as `store` — see comment there for rationale.
            'hard_assignments.*.employee_id' => [
                'required',
                'string',
                Rule::exists('employees', 'id')
                    ->where('tenant_id', app('tenant_id'))
                    ->whereNull('deleted_at'),
            ],
            'hard_assignments.*.allocated_hours' => 'required|numeric|min:0',
        ]);

        // Capacity check uses the deal's existing timeline_months if the
        // request doesn't override it (see assertCapacityFeasible).
        $this->assertCapacityFeasible($request, $deal);

        DB::transaction(function () use ($request, $deal) {
            $deal->update($request->except([
                'ghost_roles',
                'hard_assignments',
                'estimation_resources',
                'deal_overheads',
            ]));

            $this->replaceDealChildren($deal, $request);
        });

        return new DealResource($deal->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']));
    }

    /**
     * Mark a deal as Dropped. The orthogonal lifecycle flag (status stays
     * at whatever rank the deal was on); analytics can later report
     * "dropped at C/B/A". Refuses to drop status='won' (S deals are final
     * per the manager's spec).
     *
     * Replaces the old POST /deals/{id}/lose endpoint, which set
     * status='lost' and conflated rank with lifecycle.
     */
    public function drop(Request $request, Deal $deal)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $deal->drop($request->input('reason'));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new DealResource($deal->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']));
    }

    public function linkedContract(Deal $deal)
    {
        $contract = Contract::where('deal_id', $deal->id)->first();

        return $contract
            ? new ContractResource($contract)
            : response()->json(['data' => null], 200);
    }

    public function destroy(Deal $deal)
    {
        $deal->delete();

        return response()->noContent();
    }

    /**
     * Validate that no employee in the incoming hard_assignments would exceed
     * their monthly workable_hours once we account for assignments on every
     * other non-lost deal in the same tenant.
     *
     * Frontend has the same check in /crm/[id]/staffing, but a direct API
     * call could bypass it. Run this BEFORE replaceDealChildren so the write
     * never goes through when capacity is exhausted.
     *
     * Throws ValidationException (→ 422) so the response shape matches what
     * the frontend's error handler already understands.
     */
    private function assertCapacityFeasible(Request $request, ?Deal $deal = null): void
    {
        $assignments = $request->input('hard_assignments');
        if (! is_array($assignments) || empty($assignments)) {
            return;
        }

        // Resolve the effective timeline for this deal: request value first
        // (incoming change wins), else the persisted deal, else 1 month.
        $timelineMonths = max(1, (int) (
            $request->input('timeline_months')
            ?? $deal?->timeline_months
            ?? 1
        ));

        $excludeDealId = $deal?->id;
        $errors = [];

        foreach ($assignments as $index => $a) {
            $employeeId = $a['employee_id'] ?? null;
            $hours      = (float) ($a['allocated_hours'] ?? 0);
            if (! $employeeId || $hours <= 0) {
                continue;
            }

            $employee = Employee::find($employeeId);
            // Rule::exists above guarantees we find one in the same tenant,
            // but guard against soft-deletes between validation and this step.
            if (! $employee) {
                continue;
            }

            $thisDealMonthly = $hours / $timelineMonths;

            // Monthly hours already booked on OTHER open deals (excluding this one).
            // Status=lost releases booking; everything else still holds capacity.
            // COALESCE(NULLIF(timeline_months, 0), 1) so deals with NULL or 0
            // timelines still count their full allocated_hours as 1 month of
            // load — matching the frontend's `Math.max(1, timelineMonths || 1)`
            // behaviour in staffing/page.tsx. Previously the > 0 filter
            // dropped these deals from the load total entirely, so the
            // backend let bookings through that the UI would have blocked.
            $otherMonthly = (float) DB::table('deal_hard_assignments as dha')
                ->join('deals as d', 'd.id', '=', 'dha.deal_id')
                ->where('dha.tenant_id', app('tenant_id'))
                ->where('dha.employee_id', $employeeId)
                ->whereNull('d.deleted_at')
                ->where('d.status', '!=', 'lost')
                ->when($excludeDealId, fn ($q) => $q->where('d.id', '!=', $excludeDealId))
                ->sum(DB::raw('CAST(dha.allocated_hours AS REAL) / COALESCE(NULLIF(d.timeline_months, 0), 1)'));

            $totalMonthly = $thisDealMonthly + $otherMonthly;
            $capacity     = (float) ($employee->workable_hours ?? 0);

            if ($capacity > 0 && $totalMonthly > $capacity) {
                $over = round($totalMonthly - $capacity, 1);
                $errors["hard_assignments.{$index}.allocated_hours"] = [
                    "{$employee->name} would be over-allocated by {$over} h/month "
                    . "({$totalMonthly} requested vs {$capacity} capacity, including other open deals).",
                ];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function replaceDealChildren(Deal $deal, Request $request): void
    {
        $tenantId = app('tenant_id');

        if ($request->has('ghost_roles')) {
            $deal->ghost_roles()->delete();
            foreach ($request->input('ghost_roles', []) as $role) {
                $deal->ghost_roles()->create([
                    'tenant_id' => $tenantId,
                    'role_type' => $role['role_type'] ?? null,
                    'quantity' => $role['quantity'] ?? 0,
                    'months' => $role['months'] ?? 0,
                    'avg_monthly_salary' => $role['avg_monthly_salary'] ?? 0,
                    'min_monthly_salary' => $role['min_monthly_salary'] ?? 0,
                    'max_monthly_salary' => $role['max_monthly_salary'] ?? 0,
                ]);
            }
        }

        if ($request->has('hard_assignments')) {
            $deal->hard_assignments()->delete();
            foreach ($request->input('hard_assignments', []) as $assignment) {
                $deal->hard_assignments()->create([
                    'tenant_id' => $tenantId,
                    'employee_id' => $assignment['employee_id'] ?? null,
                    'allocated_hours' => $assignment['allocated_hours'] ?? 0,
                ]);
            }
        }

        if ($request->has('estimation_resources')) {
            $deal->estimation_resources()->delete();
            foreach ($request->input('estimation_resources', []) as $resource) {
                $deal->estimation_resources()->create([
                    'tenant_id' => $tenantId,
                    'role_id' => $resource['role_id'] ?? null,
                    'feature_name' => $resource['feature_name'] ?? null,
                    'hours' => $resource['hours'] ?? 0,
                ]);
            }
        }

        if ($request->has('deal_overheads')) {
            $deal->deal_overheads()->delete();
            foreach ($request->input('deal_overheads', []) as $overhead) {
                $deal->deal_overheads()->create([
                    'tenant_id' => $tenantId,
                    'name' => $overhead['name'] ?? null,
                    'cost' => $overhead['cost'] ?? 0,
                ]);
            }
        }
    }
}
