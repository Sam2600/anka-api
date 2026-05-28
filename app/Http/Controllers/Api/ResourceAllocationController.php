<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ResourceAllocationController extends Controller
{
    public function index(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->year);

        $employees = Employee::with(['department', 'capacityRole', 'rank', 'teamAssignments'])
            ->whereHas('department', fn ($q) => $q->where('is_delivery_eligible', true))
            ->where('status', 'Active')
            ->orderBy('name')
            ->get();

        $rows = [];
        $totalOperate = 0;
        $totalCells = 0;
        $totalBench = 0;
        $overAllocAlerts = 0;

        foreach ($employees as $emp) {
            $months = [];

            for ($m = 1; $m <= 12; $m++) {
                $operate = 0.0;

                foreach ($emp->teamAssignments as $a) {
                    $operate += $this->allocationForMonth($a, $year, $m);
                }

                $operate = round($operate, 2);
                $available = round(max(0, 1 - $operate), 2);

                $months[] = [
                    'month' => $m,
                    'operate' => $operate,
                    'available' => $available,
                ];

                $totalOperate += $operate;
                $totalBench += $available;
                $totalCells++;
                if ($operate > 1.0) {
                    $overAllocAlerts++;
                }
            }

            $rows[] = [
                'id' => $emp->id,
                'name' => $emp->name,
                'role' => $this->inferRole($emp),
                'capacity_role' => optional($emp->capacityRole)->code ?? $emp->capacity_role,
                'department' => optional($emp->department)->name ?? 'Unknown',
                'months' => $months,
            ];
        }

        $headcount = count($rows);
        $avgUtil = $totalCells > 0 ? round($totalOperate / $totalCells, 2) : 0;

        return response()->json([
            'year' => $year,
            'employees' => $rows,
            'summary' => [
                'headcount' => $headcount,
                'avg_utilization' => $avgUtil,
                'total_bench' => round($totalBench, 1),
                'over_allocation_alerts' => $overAllocAlerts,
            ],
        ]);
    }

    private function allocationForMonth($assignment, int $year, int $month): float
    {
        $monthlyAlloc = $assignment->monthly_allocation;
        $startDate = $assignment->team_start_date;

        if (is_array($monthlyAlloc) && $startDate) {
            $start = Carbon::parse($startDate)->startOfMonth();
            $target = Carbon::create($year, $month, 1);
            $offset = $start->diffInMonths($target, false);

            if ($offset < 0 || $offset >= count($monthlyAlloc)) {
                return 0.0;
            }

            return (float) ($monthlyAlloc[$offset] ?? 0);
        }

        // Legacy: no monthly_allocation — spread allocated_hours evenly across
        // the project's timeline if we can infer it.
        $allocated = (float) $assignment->allocated_hours;
        if ($allocated <= 0) {
            return 0.0;
        }

        $project = $assignment->project;
        if (! $project || ! $project->start_date || ! $project->end_date) {
            return 0.0;
        }

        $projStart = Carbon::parse($project->start_date)->startOfMonth();
        $projEnd = Carbon::parse($project->end_date)->endOfMonth();
        $target = Carbon::create($year, $month, 1);

        if ($target->lt($projStart) || $target->gt($projEnd)) {
            return 0.0;
        }

        $projMonths = max(1, $projStart->diffInMonths($projEnd) + 1);
        $workable = (float) ($assignment->employee?->workable_hours ?? 160);
        $monthlyFraction = ($allocated / ($workable * $projMonths));

        return min(1.0, round($monthlyFraction, 2));
    }

    private function inferRole(Employee $emp): string
    {
        $capRole = optional($emp->capacityRole)->code ?? $emp->capacity_role;
        if ($capRole === 'pm') {
            return 'leader';
        }

        return 'member';
    }
}
