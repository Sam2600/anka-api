<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Http\Resources\DealResource;
use App\Http\Resources\ContractResource;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealController extends Controller
{
    public function index(Request $request)
    {
        $query = Deal::with(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('client', 'ilike', $search);
            });
        }

        $perPage = min((int) ($request->per_page ?? 100), 500);
        return DealResource::collection($query->orderBy('created_at', 'desc')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'   => 'required|string|max:255',
            'client' => 'nullable|string|max:255',
            'status' => 'nullable|in:lead,opportunity,inquiry,proposal,contract,won,lost',
            'estimated_value'    => 'nullable|numeric|min:0',
            'win_probability'    => 'nullable|integer|min:0|max:100',
            'client_budget'      => 'nullable|numeric|min:0',
            'timeline_months'    => 'nullable|integer|min:1',
            'workload_hours'     => 'nullable|numeric|min:0',
            'target_margin'      => 'nullable|numeric|min:0|max:100',
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
            'name'   => 'sometimes|required|string|max:255',
            'client' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:lead,opportunity,inquiry,proposal,contract,won,lost',
            'estimated_value'    => 'sometimes|nullable|numeric|min:0',
            'win_probability'    => 'sometimes|nullable|integer|min:0|max:100',
            'client_budget'      => 'sometimes|nullable|numeric|min:0',
            'timeline_months'    => 'sometimes|nullable|integer|min:1',
            'workload_hours'     => 'sometimes|nullable|numeric|min:0',
            'target_margin'      => 'sometimes|nullable|numeric|min:0|max:100',
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
            'status'          => 'required|string',
            'win_probability' => 'required|integer',
        ]);

        $deal->update([
            'status'          => $request->status,
            'win_probability' => $request->win_probability,
        ]);

        return new DealResource($deal->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads']));
    }

    public function win(Request $request, Deal $deal)
    {
        DB::select('SELECT win_deal(?, ?)', [$deal->id, app('tenant_id')]);

        $contract = \App\Models\Contract::where('deal_id', $deal->id)->first();
        $project  = \App\Models\Project::where('contract_id', $contract?->id)->first();

        // Return flat (no `data` wrapper) so businessStore.winDeal() can access
        // data.deal / data.contract / data.project directly from the axios response body.
        return response()->json([
            'deal'     => (new DealResource($deal->fresh()->load(['ghost_roles', 'hard_assignments', 'estimation_resources', 'deal_overheads'])))->resolve($request),
            'contract' => $contract ? (new ContractResource($contract))->resolve($request) : null,
            'project'  => $project ? (new ProjectResource($project))->resolve($request) : null,
        ]);
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
                    'tenant_id'           => $tenantId,
                    'role_type'           => $role['role_type'] ?? null,
                    'quantity'            => $role['quantity'] ?? 0,
                    'months'              => $role['months'] ?? 0,
                    'avg_monthly_salary'  => $role['avg_monthly_salary'] ?? 0,
                ]);
            }
        }

        if ($request->has('hard_assignments')) {
            $deal->hard_assignments()->delete();
            foreach ($request->input('hard_assignments', []) as $assignment) {
                $deal->hard_assignments()->create([
                    'tenant_id'        => $tenantId,
                    'employee_id'      => $assignment['employee_id'] ?? null,
                    'allocated_hours'  => $assignment['allocated_hours'] ?? 0,
                ]);
            }
        }

        if ($request->has('estimation_resources')) {
            $deal->estimation_resources()->delete();
            foreach ($request->input('estimation_resources', []) as $resource) {
                $deal->estimation_resources()->create([
                    'tenant_id'     => $tenantId,
                    'role_id'       => $resource['role_id'] ?? null,
                    'feature_name'  => $resource['feature_name'] ?? null,
                    'hours'         => $resource['hours'] ?? 0,
                ]);
            }
        }

        if ($request->has('deal_overheads')) {
            $deal->deal_overheads()->delete();
            foreach ($request->input('deal_overheads', []) as $overhead) {
                $deal->deal_overheads()->create([
                    'tenant_id' => $tenantId,
                    'name'      => $overhead['name'] ?? null,
                    'cost'      => $overhead['cost'] ?? 0,
                ]);
            }
        }
    }
}
