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
            'status'               => 'sometimes|in:Draft,Signed,Active,Completed,Cancelled',
            'notes'                => 'sometimes|nullable|string|max:2000',
            'end_date'             => 'sometimes|nullable|date',
            'total_value'          => 'sometimes|numeric|min:0',
            'signed_at'            => 'sometimes|nullable|date',
            'payment_terms_days'   => 'sometimes|integer|min:0|max:365',
            'po_number'            => 'sometimes|nullable|string|max:100',
            'billing_contact_name' => 'sometimes|nullable|string|max:255',
            'billing_email'        => 'sometimes|nullable|email|max:255',
            'currency'             => 'sometimes|nullable|string|in:MMK,JPY,USD',
            'tax_jurisdiction'     => 'sometimes|nullable|string|max:100',
        ]);

        $contract->update($request->only([
            'status', 'notes', 'end_date', 'total_value',
            'signed_at', 'payment_terms_days', 'po_number',
            'billing_contact_name', 'billing_email', 'currency', 'tax_jurisdiction',
        ]));
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
