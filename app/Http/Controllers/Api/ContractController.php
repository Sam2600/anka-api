<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Http\Resources\ContractResource;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        $query = Contract::query();

        if ($request->filled('deal_id')) {
            $query->where('deal_id', $request->deal_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) ($request->per_page ?? 50), 200);
        return ContractResource::collection($query->orderBy('created_at', 'desc')->paginate($perPage));
    }

    public function show(Contract $contract)
    {
        return new ContractResource($contract);
    }

    public function update(Request $request, Contract $contract)
    {
        $request->validate([
            'status'      => 'sometimes|in:Active,Completed,Draft,Cancelled',
            'notes'       => 'sometimes|nullable|string|max:2000',
            'end_date'    => 'sometimes|nullable|date',
            'total_value' => 'sometimes|numeric|min:0',
        ]);

        $contract->update($request->only(['status', 'notes', 'end_date', 'total_value']));
        return new ContractResource($contract->fresh());
    }

    public function linkedProject(Contract $contract)
    {
        $project = \App\Models\Project::where('contract_id', $contract->id)->first();
        return $project
            ? new ProjectResource($project)
            : response()->json(['data' => null], 200);
    }

    public function destroy(Contract $contract)
    {
        $contract->delete();
        return response()->noContent();
    }
}
