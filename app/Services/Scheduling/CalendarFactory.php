<?php

namespace App\Services\Scheduling;

use App\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * Builds a WorkingDayCalendar pre-loaded with the tenant's holidays for a
 * given window. Used by both the scheduling side (AiAutoAssignController)
 * and the tracking side (VarianceCalculator / ScheduleTrackingController),
 * so the same blocked-day rules apply in both places.
 *
 * Future: also load approved EmployeeLeave (Phase 3 of smart scheduling)
 * once that table exists. Cross-project capacity (Phase 4) can register
 * via setEmployeeLoad() on the returned calendar.
 */
class CalendarFactory
{
    public static function forTenant(string $tenantId, Carbon $windowStart, Carbon $windowEnd): WorkingDayCalendar
    {
        $calendar = new WorkingDayCalendar(skipWeekends: true);

        Holiday::where('tenant_id', $tenantId)
            ->where(function ($q) use ($windowStart, $windowEnd) {
                $q->whereBetween('date', [$windowStart->toDateString(), $windowEnd->toDateString()])
                    ->orWhere('is_recurring', true);
            })
            ->get()
            ->each(function (Holiday $holiday) use ($calendar) {
                if ($holiday->is_recurring) {
                    $calendar->blockRecurringMonthDay(
                        (int) $holiday->date->format('n'),
                        (int) $holiday->date->format('j'),
                    );
                } else {
                    $calendar->blockGlobalDate($holiday->date);
                }
            });

        return $calendar;
    }
}
