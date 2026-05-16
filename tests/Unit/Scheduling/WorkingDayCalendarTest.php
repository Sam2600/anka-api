<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\WorkingDayCalendar;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class WorkingDayCalendarTest extends TestCase
{
    public function test_friday_plus_one_working_day_is_monday(): void
    {
        $cal = new WorkingDayCalendar();
        // 2026-05-15 is a Friday.
        $result = $cal->addWorkingDays(Carbon::parse('2026-05-15'), 1);
        $this->assertSame('2026-05-18', $result->toDateString());
    }

    public function test_next_working_day_skips_weekend(): void
    {
        $cal = new WorkingDayCalendar();
        // 2026-05-16 is Saturday.
        $result = $cal->nextWorkingDay(Carbon::parse('2026-05-16'));
        $this->assertSame('2026-05-18', $result->toDateString());
    }

    public function test_next_working_day_on_working_day_returns_same_day(): void
    {
        $cal = new WorkingDayCalendar();
        $result = $cal->nextWorkingDay(Carbon::parse('2026-05-15')); // Friday
        $this->assertSame('2026-05-15', $result->toDateString());
    }

    public function test_global_blocked_date_is_skipped(): void
    {
        $cal = new WorkingDayCalendar();
        $cal->blockGlobalDate(Carbon::parse('2026-05-18')); // Monday
        $result = $cal->addWorkingDays(Carbon::parse('2026-05-15'), 1); // Fri + 1
        $this->assertSame('2026-05-19', $result->toDateString()); // Tuesday
    }

    public function test_recurring_holiday_is_skipped(): void
    {
        $cal = new WorkingDayCalendar();
        $cal->blockRecurringMonthDay(5, 18); // every May 18
        $result = $cal->addWorkingDays(Carbon::parse('2026-05-15'), 1);
        $this->assertSame('2026-05-19', $result->toDateString());
    }

    public function test_employee_leave_range_blocks_only_that_employee(): void
    {
        $cal = new WorkingDayCalendar();
        $cal->blockEmployeeRange('emp-1', Carbon::parse('2026-05-18'), Carbon::parse('2026-05-22'));
        // emp-1 hits leave from Mon-Fri, lands on the next working Monday.
        $resultA = $cal->addWorkingDays(Carbon::parse('2026-05-15'), 1, 'emp-1');
        $this->assertSame('2026-05-25', $resultA->toDateString());
        // emp-2 is unaffected.
        $resultB = $cal->addWorkingDays(Carbon::parse('2026-05-15'), 1, 'emp-2');
        $this->assertSame('2026-05-18', $resultB->toDateString());
    }

    public function test_add_zero_working_days_returns_next_working_day(): void
    {
        $cal = new WorkingDayCalendar();
        // From a Saturday, 0 working days = next Monday.
        $result = $cal->addWorkingDays(Carbon::parse('2026-05-16'), 0);
        $this->assertSame('2026-05-18', $result->toDateString());
    }

    public function test_holiday_on_weekend_does_not_double_skip(): void
    {
        $cal = new WorkingDayCalendar();
        $cal->blockGlobalDate(Carbon::parse('2026-05-16')); // Saturday — already non-working
        // Friday + 1 still lands on Monday.
        $result = $cal->addWorkingDays(Carbon::parse('2026-05-15'), 1);
        $this->assertSame('2026-05-18', $result->toDateString());
    }
}
