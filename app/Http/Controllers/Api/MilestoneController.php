<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MilestoneResource;
use App\Models\Milestone;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    public function index(Request $request)
    {
        $query = Milestone::query();

        if ($request->filled('contract_id')) {
            $query->where('contract_id', $request->contract_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) ($request->per_page ?? 50), 200);
        return MilestoneResource::collection($query->orderBy('due_date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'contract_id' => 'required|uuid|exists:contracts,id',
            'name'        => 'required|string|max:255',
            'due_date'    => 'required|date',
            'amount'      => 'required|numeric|min:0',
            'status'      => 'nullable|in:Pending,In Progress,Completed',
        ]);

        $validated['status'] = $validated['status'] ?? 'Pending';
        $milestone = Milestone::create($validated);

        return new MilestoneResource($milestone);
    }

    public function show(Milestone $milestone)
    {
        return new MilestoneResource($milestone);
    }

    public function update(Request $request, Milestone $milestone)
    {
        $validated = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'due_date' => 'sometimes|required|date',
            'amount'   => 'sometimes|required|numeric|min:0',
            'status'   => 'sometimes|in:Pending,In Progress,Completed',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'Completed' && !$milestone->completed_at) {
            $validated['completed_at'] = now();
        }

        $milestone->update($validated);
        return new MilestoneResource($milestone->fresh());
    }

    public function destroy(Milestone $milestone)
    {
        $milestone->delete();
        return response()->noContent();
    }
}
