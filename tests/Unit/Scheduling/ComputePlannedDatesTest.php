<?php

namespace Tests\Unit\Scheduling;

use App\Http\Controllers\Api\AiAutoAssignController;
use App\Services\Scheduling\WorkingDayCalendar;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Hour-packed scheduler — covers the Pattern D scenario from
 * HOUR_PACKING_SCHEDULER_DISCUSSION.md and edge cases around budget overflow,
 * exact 8h fits, > 8h spans, and weekend skipping via WorkingDayCalendar.
 *
 * computePlannedDates() is private on AiAutoAssignController; we invoke it via
 * reflection because the function itself has no dependencies on the rest of
 * the controller (request/DB/etc) and is the unit under test.
 */
class ComputePlannedDatesTest extends TestCase
{
    private AiAutoAssignController $controller;

    private \ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AiAutoAssignController;
        $rc = new ReflectionClass($this->controller);
        $this->method = $rc->getMethod('computePlannedDates');
        $this->method->setAccessible(true);
    }

    private function invoke(array $tasks, array $assigneeByRowPhase, string $startDate, string $endDate, ?WorkingDayCalendar $calendar = null): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end   = Carbon::parse($endDate)->startOfDay();
        $cal   = $calendar ?? new WorkingDayCalendar(true);

        return $this->method->invoke(
            $this->controller,
            $tasks,
            $assigneeByRowPhase,
            $start,
            $end,
            $cal
        );
    }

    public function test_pattern_d_canonical_scenario(): void
    {
        // 3 tasks, 7 phases. Alice = 'alice', Bob = 'bob'.
        // Project starts Mon 2026-06-01, effective end far enough out.
        $tasks = [
            [
                'row_no' => 1, 'difficulty' => '普通', 'total_hours' => 10.5,
                'function_name' => 'Login',
                'phases' => [
                    ['code' => 'requirement', 'order' => 1, 'hours' => 3.5, 'name' => '要件定義'],
                    ['code' => 'detail_doc',  'order' => 4, 'hours' => 2.5, 'name' => '詳細設計'],
                    ['code' => 'development', 'order' => 5, 'hours' => 4.0, 'name' => 'Development'],
                    ['code' => 'unit_test',   'order' => 6, 'hours' => 0.5, 'name' => '単体テスト'],
                ],
            ],
            [
                'row_no' => 2, 'difficulty' => '普通', 'total_hours' => 10.0,
                'function_name' => 'Dashboard',
                'phases' => [
                    ['code' => 'requirement', 'order' => 1, 'hours' => 4.0, 'name' => '要件定義'],
                    ['code' => 'development', 'order' => 5, 'hours' => 6.0, 'name' => 'Development'],
                ],
            ],
            [
                'row_no' => 3, 'difficulty' => '難しい', 'total_hours' => 17.0,
                'function_name' => 'Reporting',
                'phases' => [
                    ['code' => 'development', 'order' => 5, 'hours' => 17.0, 'name' => 'Development'],
                ],
            ],
        ];

        $assignees = [
            1 => ['requirement' => 'alice', 'detail_doc' => 'alice', 'development' => 'bob', 'unit_test' => 'bob'],
            2 => ['requirement' => 'alice', 'development' => 'bob'],
            3 => ['development' => 'bob'],
        ];

        $r = $this->invoke($tasks, $assignees, '2026-06-01', '2026-06-30');

        // T1
        $this->assertSame(['planned_start' => '2026-06-01', 'planned_end' => '2026-06-01', 'start_day_hours' => 3.5], $this->trim($r[1]['requirement']));
        $this->assertSame(['planned_start' => '2026-06-01', 'planned_end' => '2026-06-01', 'start_day_hours' => 2.5], $this->trim($r[1]['detail_doc']));
        $this->assertSame(['planned_start' => '2026-06-01', 'planned_end' => '2026-06-01', 'start_day_hours' => 4.0], $this->trim($r[1]['development']));
        $this->assertSame(['planned_start' => '2026-06-01', 'planned_end' => '2026-06-01', 'start_day_hours' => 0.5], $this->trim($r[1]['unit_test']));

        // T2 — Alice's Mon already at 6h (3.5+2.5); 4h splits Mon 2h + Tue 2h.
        $this->assertSame(['planned_start' => '2026-06-01', 'planned_end' => '2026-06-02', 'start_day_hours' => 2.0], $this->trim($r[2]['requirement']));
        // Bob's Mon was 4.5h used; T2 requires task to be on Tue+ (task cursor advanced to Tue from Alice's req).
        // Bob is fresh on Tue → 6h fits same day.
        $this->assertSame(['planned_start' => '2026-06-02', 'planned_end' => '2026-06-02', 'start_day_hours' => 6.0], $this->trim($r[2]['development']));

        // T3 — Bob's Tue at 6h used. 17h splits Tue 2h + Wed 8h + Thu 7h.
        $this->assertSame(['planned_start' => '2026-06-02', 'planned_end' => '2026-06-04', 'start_day_hours' => 2.0], $this->trim($r[3]['development']));
    }

    public function test_exactly_8h_phase_fills_day_and_next_rolls(): void
    {
        $tasks = [
            [
                'row_no' => 1, 'difficulty' => '普通', 'total_hours' => 16.0,
                'function_name' => 'Atomic',
                'phases' => [
                    ['code' => 'requirement', 'order' => 1, 'hours' => 8.0, 'name' => '要件定義'],
                    ['code' => 'detail_doc',  'order' => 4, 'hours' => 4.0, 'name' => '詳細設計'],
                ],
            ],
        ];
        $assignees = [1 => ['requirement' => 'alice', 'detail_doc' => 'alice']];

        $r = $this->invoke($tasks, $assignees, '2026-06-01', '2026-06-30');

        // 8h fits in Mon's 8h budget exactly.
        $this->assertSame('2026-06-01', $r[1]['requirement']['planned_start']);
        $this->assertSame('2026-06-01', $r[1]['requirement']['planned_end']);
        $this->assertEqualsWithDelta(8.0, $r[1]['requirement']['start_day_hours'], 0.01);

        // Next 4h phase must roll to Tue (Mon fully booked).
        $this->assertSame('2026-06-02', $r[1]['detail_doc']['planned_start']);
        $this->assertSame('2026-06-02', $r[1]['detail_doc']['planned_end']);
        $this->assertEqualsWithDelta(4.0, $r[1]['detail_doc']['start_day_hours'], 0.01);
    }

    public function test_9h_phase_spans_two_days(): void
    {
        $tasks = [
            [
                'row_no' => 1, 'difficulty' => '普通', 'total_hours' => 9.0,
                'function_name' => 'BigDay',
                'phases' => [
                    ['code' => 'development', 'order' => 5, 'hours' => 9.0, 'name' => 'Development'],
                ],
            ],
        ];
        $assignees = [1 => ['development' => 'bob']];

        $r = $this->invoke($tasks, $assignees, '2026-06-01', '2026-06-30');

        // 9h: Mon 8h + Tue 1h.
        $this->assertSame('2026-06-01', $r[1]['development']['planned_start']);
        $this->assertSame('2026-06-02', $r[1]['development']['planned_end']);
        $this->assertEqualsWithDelta(8.0, $r[1]['development']['start_day_hours'], 0.01);
    }

    public function test_weekend_skipping(): void
    {
        // Project starts Fri 2026-06-05. Alice has 5h on Fri then 4h.
        // Pattern D: Fri gets 5h, then 3h spills — but Fri only has 8 total
        // and 5 is already used → 4h doesn't fit (3h remaining). Split: Fri 3h + Mon 1h.
        $tasks = [
            [
                'row_no' => 1, 'difficulty' => '普通', 'total_hours' => 9.0,
                'function_name' => 'WeekendSplit',
                'phases' => [
                    ['code' => 'requirement', 'order' => 1, 'hours' => 5.0, 'name' => '要件定義'],
                    ['code' => 'detail_doc',  'order' => 4, 'hours' => 4.0, 'name' => '詳細設計'],
                ],
            ],
        ];
        $assignees = [1 => ['requirement' => 'alice', 'detail_doc' => 'alice']];

        // 2026-06-05 is a Friday.
        $r = $this->invoke($tasks, $assignees, '2026-06-05', '2026-06-30');

        $this->assertSame('2026-06-05', $r[1]['requirement']['planned_start']); // Fri
        $this->assertSame('2026-06-05', $r[1]['requirement']['planned_end']);
        $this->assertEqualsWithDelta(5.0, $r[1]['requirement']['start_day_hours'], 0.01);

        // 4h with only 3h remaining Fri → splits Fri 3h + Mon 1h (Sat/Sun skipped).
        $this->assertSame('2026-06-05', $r[1]['detail_doc']['planned_start']); // Fri
        $this->assertSame('2026-06-08', $r[1]['detail_doc']['planned_end']);   // Mon (skipped Sat/Sun)
        $this->assertEqualsWithDelta(3.0, $r[1]['detail_doc']['start_day_hours'], 0.01);
    }

    public function test_no_assignee_phase_is_skipped(): void
    {
        $tasks = [
            [
                'row_no' => 1, 'difficulty' => '普通', 'total_hours' => 5.0,
                'function_name' => 'Skipped',
                'phases' => [
                    ['code' => 'requirement', 'order' => 1, 'hours' => 5.0, 'name' => '要件定義'],
                ],
            ],
        ];
        // No assignee for row 1's requirement.
        $r = $this->invoke($tasks, [], '2026-06-01', '2026-06-30');

        $this->assertArrayNotHasKey(1, $r);
    }

    private function trim(array $entry): array
    {
        return [
            'planned_start'   => $entry['planned_start'],
            'planned_end'     => $entry['planned_end'],
            'start_day_hours' => (float) $entry['start_day_hours'],
        ];
    }
}
