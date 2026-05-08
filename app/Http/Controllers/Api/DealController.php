<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\ProjectResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Project;
use App\Models\ProjectTeamAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'status' => 'nullable|in:lead,inquiry,opportunity,proposal,contract,won,lost',
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
            'hard_assignments.*.employee_id' => 'required|string',
            'hard_assignments.*.allocated_hours' => 'required|numeric|min:0',
        ]);

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
            'status' => 'sometimes|in:lead,inquiry,opportunity,proposal,contract,won,lost',
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
            'ghost_roles.*.role_type' => 'required|string|in:frontend,backend,pm,qa,design',
            'ghost_roles.*.quantity' => 'required|integer|min:1',
            'ghost_roles.*.months' => 'required|integer|min:1',
            'ghost_roles.*.avg_monthly_salary' => 'required|numeric|min:0',
            'ghost_roles.*.min_monthly_salary' => 'nullable|numeric|min:0',
            'ghost_roles.*.max_monthly_salary' => 'nullable|numeric|min:0',
            'hard_assignments' => 'sometimes|array',
            'hard_assignments.*.employee_id' => 'required|string',
            'hard_assignments.*.allocated_hours' => 'required|numeric|min:0',
        ]);

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
            'status' => 'required|in:lead,inquiry,opportunity,proposal,contract,won,lost',
            'win_probability' => 'required|integer|min:0|max:100',
        ]);

        // Server-side probability defaults per stage, applied when client doesn't send one.
        $stageProbabilities = [
            'lead' => 10,
            'inquiry' => 20,
            'opportunity' => 40,
            'proposal' => 60,
            'contract' => 80,
            'won' => 100,
            'lost' => 0,
        ];

        $probability = $request->win_probability
            ?? ($stageProbabilities[$request->status] ?? 50);

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
