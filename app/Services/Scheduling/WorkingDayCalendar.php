<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Carbon;

/**
 * Working-day arithmetic for the AI task scheduler.
 *
 * Treats Saturday/Sunday as non-working by default and lets callers register
 * additional blocked dates — either globally (public holidays) or scoped to a
 * single employee (PTO / sick leave). Used by AiAutoAssignController so that
 * planned_start / planned_end never land on weekends or holidays.
 *
 * The class is pure and stateful per-request; instantiate one per scheduling
 * run, register blockages, then call addWorkingDays() / nextWorkingDay().
 */
class WorkingDayCalendar
{
    /** @var array<string, true> ISO dates (YYYY-MM-DD) globally blocked */
    private array $globalBlocked = [];

    /** @var array<string, array<string, true>> employee_id => [YYYY-MM-DD => true] */
    private array $employeeBlocked = [];

    /** @var array<string, true> MM-DD strings for holidays that recur every year */
    private array $recurringMonthDay = [];

    public function __construct(
        private bool $skipWeekends = true,
    ) {}

    public function blockGlobalDate(Carbon|string $date): void
    {
        $key = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $this->globalBlocked[$key] = true;
    }

    public function blockRecurringMonthDay(int $month, int $day): void
    {
        $this->recurringMonthDay[sprintf('%02d-%02d', $month, $day)] = true;
    }

    public function blockEmployeeRange(string $employeeId, Carbon $start, Carbon $end): void
    {
        $cursor = $start->copy()->startOfDay();
        $stop   = $end->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($stop)) {
            $this->employeeBlocked[$employeeId][$cursor->toDateString()] = true;
            $cursor->addDay();
        }
    }

    public function isWorkingDay(Carbon $date, ?string $employeeId = null): bool
    {
        if ($this->skipWeekends && $date->isWeekend()) {
            return false;
        }
        $iso = $date->toDateString();
        if (isset($this->globalBlocked[$iso])) {
            return false;
        }
        if (isset($this->recurringMonthDay[$date->format('m-d')])) {
            return false;
        }
        if ($employeeId !== null && isset($this->employeeBlocked[$employeeId][$iso])) {
            return false;
        }

        return true;
    }

    /**
     * Returns $date if it's already a working day, otherwise advances to the
     * next working day. Walks at most ~400 days to avoid infinite loops when
     * the calendar is misconfigured.
     */
    public function nextWorkingDay(Carbon $date, ?string $employeeId = null): Carbon
    {
        $cursor = $date->copy()->startOfDay();
        for ($i = 0; $i < 400; $i++) {
            if ($this->isWorkingDay($cursor, $employeeId)) {
                return $cursor;
            }
            $cursor->addDay();
        }

        return $cursor;
    }

    /**
     * Advances $from by $n working days. If $from itself is non-working, the
     * count starts from the next working day. Returns $from unchanged when
     * $n is 0 and $from is already a working day.
     */
    public function addWorkingDays(Carbon $from, int $n, ?string $employeeId = null): Carbon
    {
        $cursor = $this->nextWorkingDay($from, $employeeId);
        if ($n <= 0) {
            return $cursor;
        }
        $added = 0;
        while ($added < $n) {
            $cursor->addDay();
            if ($this->isWorkingDay($cursor, $employeeId)) {
                $added++;
            }
        }

        return $cursor;
    }

    /**
     * Inclusive count of working days in the [$start, $end] range. Both ends
     * are counted if they are themselves working days. Returns 0 when end < start.
     */
    public function workingDaysBetween(Carbon $start, Carbon $end, ?string $employeeId = null): int
    {
        if ($end->lessThan($start)) {
            return 0;
        }
        $cursor = $start->copy()->startOfDay();
        $stop   = $end->copy()->startOfDay();
        $count  = 0;
        while ($cursor->lessThanOrEqualTo($stop)) {
            if ($this->isWorkingDay($cursor, $employeeId)) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }
}
