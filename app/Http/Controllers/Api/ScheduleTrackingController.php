<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PhaseProgressLogResource;
use App\Models\PhaseProgressLog;
use App\Models\Project;
use App\Models\ProjectTaskPhaseAssignment;
use App\Services\Scheduling\CalendarFactory;
use App\Services\Scheduling\VarianceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleTrackingController extends Controller
{
    /**
     * Data list with search + filter. Returns one row per phase assignment with
     * variance fields attached. Paginated.
     */
    public function index(Request $request, Project $project)
    {
        $request->validate(['as_of' => 'sometimes|date']);
        $tenantId = app('tenant_id');
        $project->load('contract.deal');

        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd   = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar    = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $asOf        = $request->filled('as_of') ? Carbon::parse($request->input('as_of')) : Carbon::today();
        $calc        = new VarianceCalculator($calendar, $asOf);

        // Filter progressLogs by log_date <= asOf so a simulated past date
        // doesn't see future-dated logs in cumulative sums. Without this filter,
        // VarianceCalculator's $logs->sum('progress_hours') aggregates every
        // log ever recorded on the phase, contaminating historical variance.
        $asOfDateStr = $asOf->toDateString();
        $query = ProjectTaskPhaseAssignment::query()
            ->with([
                'taskAssignment',
                'assignee.rank',
                'progressLogs' => fn ($q) => $q->whereDate('log_date', '<=', $asOfDateStr),
                'progressLogs.employee',
            ])
            ->whereHas('taskAssignment', fn ($q) => $q->where('project_id', $project->id));

        $this->applyFilters($query, $request);

        $sort = $request->input('sort', 'planned_start');
        $direction = $request->input('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $sortableColumns = ['planned_start', 'planned_end', 'phase_order', 'estimated_hours'];
        if (in_array($sort, $sortableColumns, true)) {
            $query->orderBy($sort, $direction);
        } else {
            $query->orderBy('planned_start');
        }

        $perPage = min(100, (int) $request->input('per_page', 25));
        $paginator = $query->paginate($perPage);

        $rows = $paginator->getCollection()->map(function (ProjectTaskPhaseAssignment $p) use ($calc, $request) {
            $variance = $calc->forPhase($p);

            // Optional client-side post-filters that need computed fields.
            if ($healthFilter = $request->input('health')) {
                $healths = is_array($healthFilter) ? $healthFilter : explode(',', $healthFilter);
                if (! in_array($variance['health'], $healths, true)) {
                    return null;
                }
            }
            if ($stateFilter = $request->input('state')) {
                $states = is_array($stateFilter) ? $stateFilter : explode(',', $stateFilter);
                if (! in_array($variance['schedule_state'], $states, true)) {
                    return null;
                }
            }

            return [
                'id'                  => $p->id,
                'task_assignment_id'  => $p->task_assignment_id,
                'function_id'         => optional($p->taskAssignment)->function_id,
                'function_name'       => optional($p->taskAssignment)->function_name,
                'difficulty'          => optional($p->taskAssignment)->difficulty,
                'phase_code'          => $p->phase_code,
                'phase_name'          => $p->phase_name,
                'phase_order'         => $p->phase_order,
                'estimated_hours'     => $p->estimated_hours,
                'planned_start'       => optional($p->planned_start)->toDateString(),
                'planned_end'         => optional($p->planned_end)->toDateString(),
                'actual_start'        => optional($p->actual_start)->toDateString(),
                'actual_end'          => optional($p->actual_end)->toDateString(),
                'assignee_id'         => $p->assignee_id,
                'assignee_name'       => optional($p->assignee)->name,
                'status'              => $p->status,
                'progress_logs'       => PhaseProgressLogResource::collection($p->progressLogs),
                'variance'            => $variance,
            ];
        })->filter()->values();

        return [
            'data' => $rows,
            'meta' => [
                'as_of'        => $asOf->toDateString(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'count'        => $rows->count(),
            ],
        ];
    }

    /**
     * Project rollup: totals + variance + health.
     */
    public function summary(Request $request, Project $project)
    {
        $request->validate(['as_of' => 'sometimes|date']);
        $tenantId = app('tenant_id');
        $project->load('contract.deal');

        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd   = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar    = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $asOf        = $request->filled('as_of') ? Carbon::parse($request->input('as_of')) : Carbon::today();
        $calc        = new VarianceCalculator($calendar, $asOf);

        $asOfDateStr = $asOf->toDateString();
        $phases = ProjectTaskPhaseAssignment::with([
            'progressLogs' => fn ($q) => $q->whereDate('log_date', '<=', $asOfDateStr),
        ])
            ->whereHas('taskAssignment', fn ($q) => $q->where('project_id', $project->id))
            ->get();

        $perPhase = [];
        $estimatedPerPhase = [];
        $todayExpectedHours = 0.0;
        foreach ($phases as $phase) {
            $perPhase[]          = $calc->forPhase($phase);
            $estimatedPerPhase[] = (float) $phase->estimated_hours;
            $todayExpectedHours += $calc->todayExpectedForPhase($phase);
        }

        // Today-only slice — sum of progress_hours from logs dated `as_of`.
        // Distinct from `total_progress_hours` (cumulative across all days) and
        // `expected_progress_hours` (cumulative plan-to-date). Answers "what
        // got delivered today?" for the project rollup card.
        $todayProgressHours = (float) PhaseProgressLog::query()
            ->whereHas(
                'phaseAssignment.taskAssignment',
                fn ($q) => $q->where('project_id', $project->id)
            )
            ->whereDate('log_date', $asOf->toDateString())
            ->sum('progress_hours');

        $rollup = $calc->rollup($perPhase, $estimatedPerPhase);
        $rollup['today_progress_hours'] = round($todayProgressHours, 2);
        $rollup['today_expected_hours'] = round($todayExpectedHours, 2);

        return [
            'data' => $rollup,
            'meta' => [
                'project_id'   => $project->id,
                'project_name' => $project->name,
                'as_of'        => $asOf->toDateString(),
                'window_start' => $windowStart->toDateString(),
                'window_end'   => $windowEnd->toDateString(),
            ],
        ];
    }

    /**
     * Per-assignee rollup for this project. One row per team member with
     * their personal variance across all phases they own here.
     */
    public function byAssignee(Request $request, Project $project)
    {
        $request->validate(['as_of' => 'sometimes|date']);
        $tenantId = app('tenant_id');
        $project->load('contract.deal');

        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd   = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar    = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $asOf        = $request->filled('as_of') ? Carbon::parse($request->input('as_of')) : Carbon::today();
        $calc        = new VarianceCalculator($calendar, $asOf);

        $asOfDateStr = $asOf->toDateString();
        $phases = ProjectTaskPhaseAssignment::with([
            'progressLogs' => fn ($q) => $q->whereDate('log_date', '<=', $asOfDateStr),
            'assignee',
        ])
            ->whereHas('taskAssignment', fn ($q) => $q->where('project_id', $project->id))
            ->whereNotNull('assignee_id')
            ->get()
            ->groupBy('assignee_id');

        $rows = [];
        foreach ($phases as $assigneeId => $group) {
            $perPhase = [];
            $estimatedPerPhase = [];
            foreach ($group as $phase) {
                $perPhase[]          = $calc->forPhase($phase);
                $estimatedPerPhase[] = (float) $phase->estimated_hours;
            }
            $rows[] = array_merge(
                [
                    'assignee_id'   => $assigneeId,
                    'assignee_name' => optional($group->first()->assignee)->name,
                ],
                $calc->rollup($perPhase, $estimatedPerPhase),
            );
        }

        // Sort by variance ascending (most behind first).
        usort($rows, fn ($a, $b) => $a['variance_hours'] <=> $b['variance_hours']);

        return [
            'data' => $rows,
            'meta' => [
                'project_id' => $project->id,
                'as_of'      => $asOf->toDateString(),
            ],
        ];
    }

    /**
     * Per-day per-developer late-hours breakdown for a project.
     *
     * Late hours = max(0, used_hours - progress_hours) on each daily
     * phase_progress_logs row. Surfaced for Finance to compute overtime
     * cost (sum × cost_per_hour) without re-summing time_entries.
     *
     * Returns:
     *   data:        [{log_date, employee_id, ..., late_hours, late_cost}, ...]  per-day rows
     *   by_employee: [{employee_id, ..., total_late_hours, total_late_cost}, ...] aggregates
     *   meta:        {project_id, total_late_hours, total_late_cost}
     */
    public function lateHoursByDay(Request $request, Project $project)
    {
        $request->validate(['as_of' => 'sometimes|date']);
        $asOf = $request->filled('as_of') ? Carbon::parse($request->input('as_of')) : null;

        $logs = PhaseProgressLog::with(['employee.rank', 'employee.capacityRole'])
            ->whereHas('phaseAssignment.taskAssignment', fn ($q) => $q->where('project_id', $project->id))
            ->when($asOf, fn ($q) => $q->whereDate('log_date', '<=', $asOf->toDateString()))
            ->orderByDesc('log_date')
            ->get();

        $perDay = [];
        $aggregateByEmployee = [];
        $totalLateHours = 0.0;
        $totalLateCost = 0.0;

        foreach ($logs as $log) {
            $progress = (float) $log->progress_hours;
            $used     = (float) $log->used_hours;
            $late     = max(0.0, $used - $progress);

            $emp = $log->employee;
            $costPerHour = $emp ? (float) ($emp->cost_per_hour ?? 0) : 0.0;
            $lateCost = round($late * $costPerHour, 2);

            $rankCode    = optional(optional($emp)->rank)->code;
            $capacityRole = optional(optional($emp)->capacityRole)->code ?? optional($emp)->capacity_role;
            $empName     = optional($emp)->name;

            $perDay[] = [
                'log_date'       => optional($log->log_date)->toDateString(),
                'employee_id'    => $log->employee_id,
                'employee_name'  => $empName,
                'rank_code'      => $rankCode,
                'capacity_role'  => $capacityRole,
                'progress_hours' => round($progress, 2),
                'used_hours'     => round($used, 2),
                'late_hours'     => round($late, 2),
                'cost_per_hour'  => round($costPerHour, 2),
                'late_cost'      => $lateCost,
            ];

            $totalLateHours += $late;
            $totalLateCost  += $lateCost;

            $bucket = $log->employee_id;
            if (! isset($aggregateByEmployee[$bucket])) {
                $aggregateByEmployee[$bucket] = [
                    'employee_id'         => $log->employee_id,
                    'employee_name'       => $empName,
                    'rank_code'           => $rankCode,
                    'capacity_role'       => $capacityRole,
                    'cost_per_hour'       => round($costPerHour, 2),
                    'total_progress_hours' => 0.0,
                    'total_used_hours'    => 0.0,
                    'total_late_hours'    => 0.0,
                    'total_late_cost'     => 0.0,
                    'days_count'          => 0,
                ];
            }
            $aggregateByEmployee[$bucket]['total_progress_hours'] += $progress;
            $aggregateByEmployee[$bucket]['total_used_hours']     += $used;
            $aggregateByEmployee[$bucket]['total_late_hours']     += $late;
            $aggregateByEmployee[$bucket]['total_late_cost']      += $lateCost;
            $aggregateByEmployee[$bucket]['days_count']           += 1;
        }

        // Round aggregates and sort by total_late_hours DESC.
        $byEmployee = array_map(function ($row) {
            $row['total_progress_hours'] = round($row['total_progress_hours'], 2);
            $row['total_used_hours']     = round($row['total_used_hours'], 2);
            $row['total_late_hours']     = round($row['total_late_hours'], 2);
            $row['total_late_cost']      = round($row['total_late_cost'], 2);

            return $row;
        }, array_values($aggregateByEmployee));
        usort($byEmployee, fn ($a, $b) => $b['total_late_hours'] <=> $a['total_late_hours']);

        return [
            'data'        => $perDay,
            'by_employee' => $byEmployee,
            'meta'        => [
                'project_id'        => $project->id,
                'project_name'      => $project->name,
                'as_of'             => $asOf?->toDateString(),
                'total_late_hours'  => round($totalLateHours, 2),
                'total_late_cost'   => round($totalLateCost, 2),
                'log_count'         => count($perDay),
            ],
        ];
    }

    private function applyFilters($query, Request $request): void
    {
        if ($phaseCodes = $request->input('phase_code')) {
            $phaseCodes = is_array($phaseCodes) ? $phaseCodes : explode(',', $phaseCodes);
            $query->whereIn('phase_code', $phaseCodes);
        }
        if ($assignees = $request->input('assignee_id')) {
            $assignees = is_array($assignees) ? $assignees : explode(',', $assignees);
            $query->whereIn('assignee_id', $assignees);
        }
        if ($status = $request->input('status')) {
            $statuses = is_array($status) ? $status : explode(',', $status);
            $query->whereIn('status', $statuses);
        }
        if ($from = $request->input('planned_date_from')) {
            $query->whereDate('planned_end', '>=', $from);
        }
        if ($to = $request->input('planned_date_to')) {
            $query->whereDate('planned_start', '<=', $to);
        }
        if ($search = trim((string) $request->input('search', ''))) {
            $driver = DB::connection()->getDriverName();
            $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($like, $needle) {
                $q->whereHas('taskAssignment', function ($q2) use ($like, $needle) {
                    $q2->where('function_name', $like, $needle)
                       ->orWhere('function_id', $like, $needle);
                })->orWhereHas('assignee', function ($q2) use ($like, $needle) {
                    $q2->where('name', $like, $needle);
                });
            });
        }
    }
}
