<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\ProjectResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectTeamAssignment;
use App\Services\EstimationXlsxService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            'status' => 'nullable|in:lead,qualified,negotiation,won,lost',
            'expected_close_date' => 'nullable|date',
            'lead_source' => 'nullable|in:inbound,referral,cold_outreach,social,event,partner,other',
            'estimated_value' => 'nullable|numeric|min:0',
            'win_probability' => 'nullable|integer|min:0|max:100',
            'client_budget' => 'nullable|numeric|min:0',
            'timeline_months' => 'nullable|integer|min:1',
            'workload_hours' => 'nullable|numeric|min:0',
            'target_margin' => 'nullable|numeric|min:0|max:100',
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
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'client' => 'sometimes|required|string|max:255',
            'contact_name' => 'sometimes|required|string|max:255',
            'contact_email' => 'sometimes|required|email|max:255',
            'contact_phone' => 'sometimes|required|string|max:50',
            'status' => 'sometimes|in:lead,qualified,negotiation,won,lost',
            'expected_close_date' => 'sometimes|nullable|date',
            'lead_source' => 'sometimes|nullable|in:inbound,referral,cold_outreach,social,event,partner,other',
            'estimated_value' => 'sometimes|nullable|numeric|min:0',
            'win_probability' => 'sometimes|nullable|integer|min:0|max:100',
            'client_budget' => 'sometimes|nullable|numeric|min:0',
            'timeline_months' => 'sometimes|nullable|integer|min:1',
            'workload_hours' => 'sometimes|nullable|numeric|min:0',
            'target_margin' => 'sometimes|nullable|numeric|min:0|max:100',
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

    public function updateStage(Request $request, Deal $deal)
    {
        $request->validate([
            'status' => 'required|in:lead,qualified,negotiation,won,lost',
            // win_probability is optional: when omitted we fall back to the
            // stage default below. Previously `required` here contradicted the
            // server-side default logic — clients that relied on the default
            // would always 422.
            'win_probability' => 'sometimes|integer|min:0|max:100',
        ]);

        // Server-side probability defaults per stage, applied when client doesn't send one.
        // Calibrated for an agency where most leads don't convert; tune per
        // tenant once you have real conversion-rate data.
        // 5 stages now (qualified merges the old proposal stage).
        // Frontend rank labels: lead→C, qualified→B, negotiation→A, won→S, lost→D.
        $stageProbabilities = [
            'lead' => 10,
            'qualified' => 40,
            'negotiation' => 75,
            'won' => 100,
            'lost' => 0,
        ];

        $probability = $request->has('win_probability')
            ? (int) $request->win_probability
            : ($stageProbabilities[$request->status] ?? 50);

        $deal->update([
            'status' => $request->status,
            'win_probability' => $probability,
        ]);

        return new DealResource($deal->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']));
    }

    public function win(Request $request, Deal $deal)
    {
        abort_if(
            in_array($deal->status, ['won', 'lost']),
            409,
            'This deal is already closed.'
        );

        $request->validate([
            'win_reason' => 'nullable|string|max:500',
        ]);

        // PostgreSQL: use the atomic stored procedure.
        // SQLite / other: fall back to Eloquent (SP doesn't exist).
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::select('SELECT win_deal(?, ?)', [$deal->id, app('tenant_id')]);
            } catch (QueryException $e) {
                $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

                return response()->json([
                    'message' => 'Failed to win deal: '.$message,
                ], 422);
            }
        } else {
            DB::transaction(function () use ($deal) {
                // Idempotent: don't create duplicate contract
                $existingContract = Contract::where('deal_id', $deal->id)->first();
                if (! $existingContract) {
                    // SQLite doesn't have the contract_number_seq default — generate manually.
                    // Must query across all tenants because contract_number is globally unique.
                    $lastNumber = (int) (Contract::withoutGlobalScope('tenant')->max(
                        DB::raw('CAST(SUBSTR(contract_number, 5) AS INTEGER)')
                    ) ?? 0);
                    $nextNumber = 'CON-'.str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);

                    $contract = Contract::create([
                        'id' => Str::orderedUuid(),
                        'tenant_id' => $deal->tenant_id,
                        'deal_id' => $deal->id,
                        'contract_number' => $nextNumber,
                        'client' => $deal->client ?? '',
                        'total_value' => $deal->client_budget ?? $deal->estimated_value ?? 0,
                        'status' => 'Draft',
                        'start_date' => now()->toDateString(),
                    ]);

                    // SQLite doesn't have the project_number_seq default either.
                    $lastPrj = (int) (Project::withoutGlobalScope('tenant')->max(
                        DB::raw('CAST(SUBSTR(project_number, 5) AS INTEGER)')
                    ) ?? 100);
                    $nextPrj = 'PRJ-'.str_pad((string) ($lastPrj + 1), 3, '0', STR_PAD_LEFT);

                    $project = Project::create([
                        'id' => Str::orderedUuid(),
                        'tenant_id' => $deal->tenant_id,
                        'contract_id' => $contract->id,
                        'project_number' => $nextPrj,
                        'name' => $deal->name ?? '',
                        'client' => $deal->client ?? '',
                        'budget_hours' => $deal->workload_hours ?? 0,
                        'consumed_hours' => 0,
                        'status' => 'Not Started',
                        'start_date' => now()->toDateString(),
                    ]);

                    // Transfer deal hard assignments → project team assignments
                    foreach ($deal->hard_assignments as $ha) {
                        ProjectTeamAssignment::create([
                            'tenant_id' => $deal->tenant_id,
                            'project_id' => $project->id,
                            'employee_id' => $ha->employee_id,
                            'allocated_hours' => $ha->allocated_hours,
                            'assignment_source' => 'deal_transfer',
                        ]);
                    }
                }

                $deal->update([
                    'status' => 'won',
                    'win_probability' => 100,
                    'won_at' => now(),
                ]);
            });
        }

        if ($request->filled('win_reason')) {
            $deal->update(['win_reason' => $request->win_reason]);
        }

        $contract = Contract::where('deal_id', $deal->id)->first();
        $project = Project::where('contract_id', $contract?->id)->first();

        // Move any existing estimation XLSX files from the deal-scoped path
        // (storage/app/deals/{id}/) to the project-scoped path
        // (storage/app/projects/{number}/). Runs OUTSIDE the win_deal
        // transaction — if the move fails the win still sticks, and the
        // download endpoint will lazy-regenerate at the right path.
        if ($project) {
            try {
                app(EstimationXlsxService::class)->migrateToProject($deal, $project);
            } catch (Throwable $e) {
                Log::warning('DealController@win: estimation XLSX migration failed', [
                    'deal_id' => $deal->id,
                    'project_id' => $project->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Return flat (no `data` wrapper) so businessStore.winDeal() can access
        // data.deal / data.contract / data.project directly from the axios response body.
        return response()->json([
            'deal' => (new DealResource($deal->fresh()->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads'])))->resolve($request),
            'contract' => $contract ? (new ContractResource($contract))->resolve($request) : null,
            'project' => $project ? (new ProjectResource($project))->resolve($request) : null,
        ]);
    }

    public function lose(Request $request, Deal $deal)
    {
        abort_if(
            in_array($deal->status, ['won', 'lost']),
            409,
            'This deal is already closed.'
        );

        $request->validate([
            'loss_reason' => 'required|string|max:500',
        ]);

        $deal->update([
            'status' => 'lost',
            'lost_at' => now(),
            'loss_reason' => $request->loss_reason,
            'win_probability' => 0,
        ]);

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
            $hours = (float) ($a['allocated_hours'] ?? 0);
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
            $capacity = (float) ($employee->workable_hours ?? 0);

            if ($capacity > 0 && $totalMonthly > $capacity) {
                $over = round($totalMonthly - $capacity, 1);
                $errors["hard_assignments.{$index}.allocated_hours"] = [
                    "{$employee->name} would be over-allocated by {$over} h/month "
                    ."({$totalMonthly} requested vs {$capacity} capacity, including other open deals).",
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
