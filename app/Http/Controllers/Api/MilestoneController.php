<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MilestoneResource;
use App\Models\Milestone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'contract_id'         => 'required|uuid|exists:contracts,id',
            'name'                => 'required|string|max:255',
            'due_date'            => 'required|date',
            'amount'              => 'required|numeric|min:0',
            'status'              => 'nullable|in:Pending,In Progress,Completed,Accepted',
            'acceptance_criteria' => 'nullable|string|max:2000',
            'sequence_number'     => 'nullable|integer|min:1',
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
            'name'                => 'sometimes|required|string|max:255',
            'due_date'            => 'sometimes|required|date',
            'amount'              => 'sometimes|required|numeric|min:0',
            'status'              => 'sometimes|in:Pending,In Progress,Completed,Accepted',
            'acceptance_criteria' => 'sometimes|nullable|string|max:2000',
            'sequence_number'     => 'sometimes|nullable|integer|min:1',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'Completed' && !$milestone->completed_at) {
            $validated['completed_at'] = now();
        }

        $milestone->update($validated);
        return new MilestoneResource($milestone->fresh());
    }

    /**
     * Mark a milestone as Accepted by the client. This is the legal trigger
     * that authorises invoicing — separate from `Completed` which is just
     * the delivery team's view that the work is done.
     */
    public function accept(Request $request, Milestone $milestone)
    {
        $validated = $request->validate([
            'accepted_by_client' => 'nullable|string|max:255',
            'accepted_at'        => 'nullable|date',
        ]);

        if ($milestone->status === 'Accepted') {
            return response()->json(['message' => 'Milestone is already accepted.'], 409);
        }

        DB::transaction(function () use ($milestone, $validated) {
            $milestone->update([
                'status'             => 'Accepted',
                'accepted_at'        => $validated['accepted_at'] ?? now(),
                'accepted_by_client' => $validated['accepted_by_client'] ?? $milestone->accepted_by_client,
                'completed_at'       => $milestone->completed_at ?? now(),
            ]);

            // Accrual revenue recognition: client acceptance is the moment we've
            // earned the milestone's value. Cash collection (cash_collected) is
            // tracked separately on invoice payment.
            DB::table('contracts')
                ->where('id', $milestone->contract_id)
                ->increment('revenue_recognized', (float) $milestone->amount);
        });

        return new MilestoneResource($milestone->fresh());
    }

    public function destroy(Milestone $milestone)
    {
        $milestone->delete();
        return response()->noContent();
    }
}
