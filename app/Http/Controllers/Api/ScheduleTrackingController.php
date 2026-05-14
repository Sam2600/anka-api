<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PhaseProgressLogResource;
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
        $tenantId = app('tenant_id');
        $project->load('contract.deal');

        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd   = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar    = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $asOf        = $request->filled('as_of') ? Carbon::parse($request->input('as_of')) : Carbon::today();
        $calc        = new VarianceCalculator($calendar, $asOf);

        $query = ProjectTaskPhaseAssignment::query()
            ->with(['taskAssignment', 'assignee.rank', 'progressLogs.employee'])
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
        $tenantId = app('tenant_id');
        $project->load('contract.deal');

        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd   = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar    = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $asOf        = $request->filled('as_of') ? Carbon::parse($request->input('as_of')) : Carbon::today();
        $calc        = new VarianceCalculator($calendar, $asOf);

        $phases = ProjectTaskPhaseAssignment::with('progressLogs')
            ->whereHas('taskAssignment', fn ($q) => $q->where('project_id', $project->id))
            ->get();

        $perPhase = [];
        $estimatedPerPhase = [];
        foreach ($phases as $phase) {
            $perPhase[]          = $calc->forPhase($phase);
            $estimatedPerPhase[] = (float) $phase->estimated_hours;
        }

        return [
            'data' => $calc->rollup($perPhase, $estimatedPerPhase),
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
        $tenantId = app('tenant_id');
        $project->load('contract.deal');

        $windowStart = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : Carbon::today()->subYear();
        $windowEnd   = $project->effectiveEndDate() ?? Carbon::today()->addYear();
        $calendar    = CalendarFactory::forTenant($tenantId, $windowStart, $windowEnd);
        $asOf        = $request->filled('as_of') ? Carbon::parse($request->input('as_of')) : Carbon::today();
        $calc        = new VarianceCalculator($calendar, $asOf);

        $phases = ProjectTaskPhaseAssignment::with(['progressLogs', 'assignee'])
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
