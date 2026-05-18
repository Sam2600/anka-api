<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = Project::query()->with('contract.deal');

        if ($request->filled('contract_id')) {
            $query->where('contract_id', $request->contract_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) ($request->per_page ?? 50), 200);
        return ProjectResource::collection($query->orderBy('created_at', 'desc')->paginate($perPage));
    }

    public function show(Project $project)
    {
        $project->load('contract.deal');
        return new ProjectResource($project);
    }

    public function update(Request $request, Project $project)
    {
        // `status` is intentionally NOT accepted from clients. It's computed
        // from time-tracking data via Project::maybeAutoTransition (real-time
        // on time-entry approval + nightly cron). Silently dropping the field
        // would mask client bugs, so reject the request with a clear message.
        if ($request->has('status')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Project status is computed automatically from time-tracking data and cannot be set manually.'],
            ]);
        }

        $request->validate([
            'name'               => 'sometimes|required|string|max:255',
            'budget_hours'       => 'sometimes|numeric|min:0',
            'end_date'           => 'sometimes|nullable|date',
            'consumed_hours'     => 'sometimes|numeric|min:0',
            'kickoff_date'       => 'sometimes|nullable|date',
            'project_manager_id' => 'sometimes|nullable|uuid|exists:employees,id',
        ]);

        $project->update($request->only([
            'name', 'budget_hours', 'end_date', 'consumed_hours',
            'kickoff_date', 'project_manager_id',
        ]));
        return new ProjectResource($project->fresh());
    }

    public function destroy(Project $project)
    {
        $project->delete();
        return response()->noContent();
    }
}
