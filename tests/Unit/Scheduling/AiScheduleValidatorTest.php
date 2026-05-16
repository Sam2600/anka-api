<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\AiSchedulePayload;
use App\Services\Scheduling\AiScheduleValidator;
use App\Services\Scheduling\WorkingDayCalendar;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class AiScheduleValidatorTest extends TestCase
{
    private const TEAM_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const TEAM_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    private function tasks(): array
    {
        return [
            [
                'row_no' => 1,
                'phases' => [
                    ['code' => 'requirement', 'hours' => 8.0, 'order' => 1],
                    ['code' => 'development', 'hours' => 16.0, 'order' => 5],
                ],
            ],
        ];
    }

    private function payload(array $assignments, array $calendar = [], array $capacity = []): AiSchedulePayload
    {
        $json = json_encode([
            'calendar'    => array_merge(['skip_weekends' => true, 'recurring_holidays' => [], 'blocked_dates' => []], $calendar),
            'capacity'    => $capacity,
            'assignments' => $assignments,
        ]);

        return AiSchedulePayload::fromRaw($json);
    }

    public function test_happy_path_passes(): void
    {
        // Mon 2026-05-18 → 2026-05-18 (1d, 8h at 8h/d). Dev: Tue 2026-05-19 → 2026-05-20 (2d, 16h at 8h/d).
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A, self::TEAM_B], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertSame([], $violations);
    }

    public function test_missing_assignment_reports_completeness(): void
    {
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertContains('missing_assignment', array_column($violations, 'code'));
    }

    public function test_unknown_assignee_caught(): void
    {
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => 'zzzz', 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertContains('unknown_assignee', array_column($violations, 'code'));
    }

    public function test_out_of_window_caught(): void
    {
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-04-30', 'planned_end' => '2026-04-30'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertContains('out_of_window', array_column($violations, 'code'));
    }

    public function test_inverted_range_caught(): void
    {
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-20', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-21', 'planned_end' => '2026-05-22'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertContains('inverted_range', array_column($violations, 'code'));
    }

    public function test_weekend_start_caught(): void
    {
        // 2026-05-16 is Saturday.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-16', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertContains('non_working_start', array_column($violations, 'code'));
    }

    public function test_double_booking_caught(): void
    {
        // Both phases on overlapping Mon-Tue, same assignee.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-19'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $codes = array_column($violations, 'code');
        $this->assertContains('double_booking', $codes);
    }

    public function test_phase_order_violation_caught(): void
    {
        // Development scheduled BEFORE requirement.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-19'],
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_B, 'planned_start' => '2026-05-20', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A, self::TEAM_B], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertContains('phase_order_violation', array_column($violations, 'code'));
    }

    public function test_duration_out_of_tolerance_caught(): void
    {
        // requirement (8h @ 8h/d → 1 day expected, tolerance 1..2). Use 5 days = out of range.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-22'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-25', 'planned_end' => '2026-05-26'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertContains('duration_out_of_tolerance', array_column($violations, 'code'));
    }

    public function test_capacity_override_widens_duration_tolerance(): void
    {
        // requirement 8h. With capacity 4h/day → expected 2 days, tolerance 1..3. 2-day actual passes.
        $payload = $this->payload(
            assignments: [
                ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-19'],
                ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-20', 'planned_end' => '2026-05-22'],
            ],
            capacity: [
                ['employee_id' => self::TEAM_A, 'hours_per_day' => 4, 'reason' => 'shared'],
            ],
        );

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasks(), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertNotContains('duration_out_of_tolerance', array_column($violations, 'code'));
    }
}
