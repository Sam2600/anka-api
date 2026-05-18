<?php

namespace App\Services;

use App\Models\Employee;
use App\Services\Scheduling\CalendarFactory;
use App\Services\Scheduling\WorkingDayCalendar;
use Illuminate\Support\Carbon;

/**
 * Holiday-aware capacity math for the AI scheduler, the Time Tracking
 * utilization KPI, and any other consumer that asks "how many hours can this
 * person realistically give us in a given month / window?".
 *
 * The stored `employees.workable_hours` is the *cost basis* — it parametrises
 * the generated `cost_per_hour` column (`monthly_salary / workable_hours`).
 * That number is intentionally stable so finance/P&L numbers don't jitter
 * month-to-month. Available hours, on the other hand, scale with the actual
 * calendar — fewer working days in February means fewer billable hours,
 * regardless of cost basis.
 *
 * Standard workdays per month is fixed at 20 — the Japanese-business
 * convention used to derive an employee's effective per-day rate.
 */
class EmployeeCapacityService
{
    public const STANDARD_WORKDAYS_PER_MONTH = 20;

    /**
     * Hours per working day for this employee. Derived from cost-basis
     * `workable_hours` divided by the standard 20-workday month.
     */
    public function dailyHours(Employee $employee): float
    {
        $monthly = (float) ($employee->workable_hours ?? 0);
        if ($monthly <= 0) {
            return 0.0;
        }

        return $monthly / self::STANDARD_WORKDAYS_PER_MONTH;
    }

    /**
     * Real available hours for the calendar month, after deducting weekends
     * and tenant-registered holidays. Build the calendar once with the
     * employee's tenant.
     */
    public function monthlyAvailableHours(Employee $employee, int $year, int $month): float
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->windowAvailableHours($employee, $start, $end);
    }

    /**
     * Hours available between two dates, inclusive on both ends. The window
     * may span multiple months; the calendar handles holidays/weekends
     * uniformly across the range.
     */
    public function windowAvailableHours(Employee $employee, Carbon $from, Carbon $to): float
    {
        $tenantId = $employee->tenant_id ?? app('tenant_id');
        $calendar = CalendarFactory::forTenant($tenantId, $from, $to);

        return $this->dailyHours($employee) * $calendar->workingDaysBetween($from, $to);
    }

    /**
     * Variant that reuses a pre-built calendar to avoid re-querying holidays
     * inside loops (e.g. the AI scheduler computes one calendar per project
     * window and reuses it for every team member).
     */
    public function windowAvailableHoursWith(Employee $employee, Carbon $from, Carbon $to, WorkingDayCalendar $calendar): float
    {
        return $this->dailyHours($employee) * $calendar->workingDaysBetween($from, $to);
    }
}
