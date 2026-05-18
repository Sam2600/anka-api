<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeCapacityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TeamCapacityController extends Controller
{
    public function __construct(private EmployeeCapacityService $capacity) {}

    /**
     * Sum of holiday-aware available hours across the tenant's active
     * employees, for an arbitrary date range. Defaults to the current month
     * when no range is provided.
     *
     * Powers the Time Tracking "Available Team Utilization" KPI — the
     * denominator drops in months with more public holidays, so utilization
     * percentages stay accurate without admins manually tweaking each
     * employee's workable_hours.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : Carbon::now()->startOfMonth();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->startOfDay()
            : Carbon::now()->endOfMonth();

        $employees = Employee::query()->where('status', 'Active')->get();

        $available = 0.0;
        $workableBaseline = 0.0;
        foreach ($employees as $employee) {
            $available += $this->capacity->windowAvailableHours($employee, $from, $to);
            $workableBaseline += (float) ($employee->workable_hours ?? 0);
        }

        return [
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'active_employees' => $employees->count(),
                'available_hours' => round($available, 2),
                'cost_basis_hours' => round($workableBaseline, 2),
            ],
        ];
    }
}
