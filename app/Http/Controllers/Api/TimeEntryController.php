<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Http\Resources\TimeEntryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
            // Accept either a single value, a CSV string, or an array. CSV is what
            // the My Tasks page sends so the "Open" tab can match Draft + Rejected.
            $raw = $request->input('status');
            $statuses = is_array($raw) ? $raw : explode(',', (string) $raw);
            $statuses = array_values(array_filter(array_map('trim', $statuses)));
            if (count($statuses) === 1) {
                $query->where('status', $statuses[0]);
            } elseif (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }
        if ($request->filled('q')) {
            // Substring match on the task description. Escape % and _ so a
            // user typing "50%" doesn't accidentally widen the search.
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $request->q);
            $query->where('task', 'like', '%' . $needle . '%');
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
            'project_id'  => 'required|exists:projects,id',
            'employee_id' => 'required|exists:employees,id',
            'task'        => 'required|string|max:500',
            'date'        => 'required|date',
            'hours'       => 'required|numeric|min:0.5|max:80',
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

    /**
     * Employee marks an assigned entry as self-completed: Draft -> Pending.
     * Only the assigned employee (matched by users.employee_id) may call this.
     */
    public function submit(TimeEntry $timeEntry)
    {
        $user = Auth::user();
        if (! $user || ! $user->employee_id || $user->employee_id !== $timeEntry->employee_id) {
            throw new HttpException(403, 'You can only submit your own assignments.');
        }
        if ($timeEntry->status !== 'Draft') {
            throw new HttpException(409, "Cannot submit an entry in status {$timeEntry->status}.");
        }

        $timeEntry->update(['status' => 'Pending']);
        return new TimeEntryResource($timeEntry->fresh());
    }

    /**
     * Manager rejects a self-completed entry: Pending -> Rejected.
     * Only manager-level roles may call this.
     */
    public function reject(TimeEntry $timeEntry)
    {
        $user = Auth::user();
        $managerRoles = ['Admin', 'Executive', 'Delivery'];
        if (! $user || ! in_array($user->app_role, $managerRoles, true)) {
            throw new HttpException(403, 'Only managers can reject entries.');
        }
        if ($timeEntry->status !== 'Pending') {
            throw new HttpException(409, "Cannot reject an entry in status {$timeEntry->status}.");
        }

        $timeEntry->update(['status' => 'Rejected']);
        return new TimeEntryResource($timeEntry->fresh());
    }

    public function destroy(TimeEntry $timeEntry)
    {
        $timeEntry->delete();
        return response()->noContent();
    }
}
