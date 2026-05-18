<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PhaseProgressLogResource;
use App\Models\PhaseProgressLog;
use App\Models\ProjectTaskPhaseAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PhaseProgressLogController extends Controller
{
    public function index(ProjectTaskPhaseAssignment $phaseAssignment)
    {
        $logs = PhaseProgressLog::with('employee')
            ->where('phase_assignment_id', $phaseAssignment->id)
            ->orderBy('log_date')
            ->get();

        return PhaseProgressLogResource::collection($logs);
    }

    public function store(Request $request, ProjectTaskPhaseAssignment $phaseAssignment)
    {
        $tenantId = app('tenant_id');

        $validated = $request->validate([
            'log_date'       => 'sometimes|date',
            'progress_hours' => 'required|numeric|min:0',
            'used_hours'     => 'required|numeric|min:0',
            'note'           => 'nullable|string|max:2000',
            'employee_id'    => 'sometimes|uuid|exists:employees,id',
        ]);

        $employeeId = $validated['employee_id'] ?? $this->resolveEmployeeId($request, $phaseAssignment);
        if (! $employeeId) {
            return response()->json([
                'error' => 'Unable to resolve employee_id. Provide one explicitly or ensure the authenticated user has a linked employee.',
            ], 422);
        }

        $logDate = isset($validated['log_date']) ? Carbon::parse($validated['log_date'])->toDateString() : Carbon::today()->toDateString();

        // Explicit find-then-update/create so the lookup matches across the
        // `date` cast (stored as datetime 'YYYY-MM-DD 00:00:00') without
        // tripping the unique index. updateOrCreate() compares raw strings
        // and would mis-match a same-day row, then INSERT and 23000.
        $log = PhaseProgressLog::where('phase_assignment_id', $phaseAssignment->id)
            ->where('employee_id', $employeeId)
            ->whereDate('log_date', $logDate)
            ->first();

        if ($log) {
            if ($log->locked_at) {
                return response()->json(['error' => 'This log is locked and cannot be modified.'], 403);
            }
            $log->update([
                'progress_hours' => $validated['progress_hours'],
                'used_hours'     => $validated['used_hours'],
                'note'           => $validated['note'] ?? null,
            ]);
        } else {
            $log = PhaseProgressLog::create([
                'tenant_id'           => $tenantId,
                'phase_assignment_id' => $phaseAssignment->id,
                'employee_id'         => $employeeId,
                'log_date'            => $logDate,
                'progress_hours'      => $validated['progress_hours'],
                'used_hours'          => $validated['used_hours'],
                'note'                => $validated['note'] ?? null,
            ]);
        }

        $log->load('employee');

        return new PhaseProgressLogResource($log);
    }

    public function update(Request $request, PhaseProgressLog $log)
    {
        if ($log->locked_at) {
            return response()->json(['error' => 'This log is locked and cannot be modified.'], 403);
        }

        $validated = $request->validate([
            'progress_hours' => 'sometimes|numeric|min:0',
            'used_hours'     => 'sometimes|numeric|min:0',
            'note'           => 'nullable|string|max:2000',
        ]);

        $log->update($validated);
        $log->load('employee');

        return new PhaseProgressLogResource($log);
    }

    public function destroy(PhaseProgressLog $log)
    {
        if ($log->locked_at) {
            return response()->json(['error' => 'This log is locked and cannot be deleted.'], 403);
        }

        $log->delete();

        return response()->noContent();
    }

    /**
     * PM / super-admin override: unlock a locked log so the employee can edit it.
     */
    public function unlock(PhaseProgressLog $log)
    {
        $log->update(['locked_at' => null]);
        $log->load('employee');

        return new PhaseProgressLogResource($log);
    }

    /**
     * Employee-facing: "what should I be working on today?" Returns the active
     * phases for the authenticated user, with their existing log for today (if any).
     */
    public function today(Request $request)
    {
        $tenantId = app('tenant_id');
        $employeeId = $this->resolveEmployeeId($request);

        if (! $employeeId) {
            return response()->json(['data' => [], 'meta' => ['employee_id' => null]]);
        }

        $today = Carbon::today();

        $phases = ProjectTaskPhaseAssignment::with([
                'taskAssignment.project',
                'assignee',
                'progressLogs' => fn ($q) => $q->where('employee_id', $employeeId)->where('log_date', $today->toDateString()),
            ])
            ->where('tenant_id', $tenantId)
            ->where('assignee_id', $employeeId)
            ->where(function ($q) use ($today) {
                $q->where(function ($q2) use ($today) {
                    $q2->whereDate('planned_start', '<=', $today)
                       ->whereDate('planned_end',   '>=', $today);
                })
                ->orWhereNull('actual_end');
            })
            ->orderBy('planned_start')
            ->get();

        return [
            'data' => $phases->map(function (ProjectTaskPhaseAssignment $p) {
                $todayLog = $p->progressLogs->first();

                return [
                    'phase_assignment_id' => $p->id,
                    'task_assignment_id'  => $p->task_assignment_id,
                    'phase_code'          => $p->phase_code,
                    'phase_name'          => $p->phase_name,
                    'estimated_hours'     => $p->estimated_hours,
                    'planned_start'       => optional($p->planned_start)->toDateString(),
                    'planned_end'         => optional($p->planned_end)->toDateString(),
                    'status'              => $p->status,
                    'function_name'       => optional($p->taskAssignment)->function_name,
                    'function_id'         => optional($p->taskAssignment)->function_id,
                    'project_id'          => optional(optional($p->taskAssignment)->project)->id,
                    'project_name'        => optional(optional($p->taskAssignment)->project)->name,
                    'today_log'           => $todayLog ? new PhaseProgressLogResource($todayLog) : null,
                ];
            }),
            'meta' => [
                'employee_id' => $employeeId,
                'log_date'    => $today->toDateString(),
            ],
        ];
    }

    /**
     * Tenant-wide aggregate for the Time Tracking page KPI card.
     * Returns SUM(progress_hours), SUM(used_hours), COUNT(*) filtered by an
     * optional date range and phase status.
     */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'date_from'    => 'sometimes|date',
            'date_to'      => 'sometimes|date',
            'phase_status' => 'sometimes|string|in:未着手,進行中,完了',
        ]);

        $query = PhaseProgressLog::query();

        if (! empty($validated['date_from'])) {
            $query->whereDate('log_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('log_date', '<=', $validated['date_to']);
        }
        if (! empty($validated['phase_status'])) {
            $query->whereHas('phaseAssignment', function ($q) use ($validated) {
                $q->where('status', $validated['phase_status']);
            });
        }

        $row = $query->selectRaw('COALESCE(SUM(progress_hours), 0) AS total_progress_hours')
            ->selectRaw('COALESCE(SUM(used_hours), 0) AS total_used_hours')
            ->selectRaw('COUNT(*) AS log_count')
            ->first();

        return [
            'data' => [
                'total_progress_hours' => (float) ($row->total_progress_hours ?? 0),
                'total_used_hours'     => (float) ($row->total_used_hours ?? 0),
                'log_count'            => (int)   ($row->log_count ?? 0),
            ],
        ];
    }

    private function resolveEmployeeId(Request $request, ?ProjectTaskPhaseAssignment $phase = null): ?string
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        if ($user->employee_id) {
            return $user->employee_id;
        }
        // Last resort — phase's planned assignee. Helps testing but in normal
        // operation every user has a linked employee row.
        return $phase?->assignee_id;
    }
}
