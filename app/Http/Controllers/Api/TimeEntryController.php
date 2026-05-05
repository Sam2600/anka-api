<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Http\Resources\TimeEntryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class TimeEntryController extends Controller
{
    public function index(Request $request)
    {
        $query = TimeEntry::query();

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $perPage = min((int) ($request->per_page ?? 50), 200);
        return TimeEntryResource::collection($query->orderBy('date', 'desc')->paginate($perPage));
    }

    public function show(TimeEntry $timeEntry)
    {
        return new TimeEntryResource($timeEntry);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id'  => 'required|uuid|exists:projects,id',
            'employee_id' => 'required|uuid|exists:employees,id',
            'task'        => 'required|string|max:500',
            'date'        => 'required|date',
            'hours'       => 'required|numeric|min:0.5|max:24',
            'billable'    => 'sometimes|boolean',
            'notes'       => 'nullable|string|max:2000',
        ]);

        $validated['status'] = 'Draft';
        $entry = TimeEntry::create($validated);
        return new TimeEntryResource($entry);
    }

    public function approve(TimeEntry $timeEntry)
    {
        DB::transaction(function () use ($timeEntry) {
            $entry = TimeEntry::lockForUpdate()->findOrFail($timeEntry->id);
            if ($entry->status === 'Approved') {
                throw new Exception('Already approved');
            }
            $entry->update(['status' => 'Approved', 'approved_at' => now()]);
            DB::table('projects')
                ->where('id', $entry->project_id)
                ->increment('consumed_hours', $entry->hours);
        });

        return new TimeEntryResource($timeEntry->fresh());
    }

    public function destroy(TimeEntry $timeEntry)
    {
        $timeEntry->delete();
        return response()->noContent();
    }
}
