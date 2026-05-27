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

    private function tasksWithDifficulty(string $difficulty): array
    {
        return [
            [
                'row_no' => 1,
                'difficulty' => $difficulty,
                'phases' => [
                    ['code' => 'requirement', 'hours' => 8.0, 'order' => 1],
                    ['code' => 'development', 'hours' => 16.0, 'order' => 5],
                ],
            ],
        ];
    }

    public function test_rank_mismatch_fires_when_junior_owns_doc_phase(): void
    {
        // requirement is a doc/design phase — needs Senior (30) or higher.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasksWithDifficulty('普通'), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
            rankLevelByAssignee: [self::TEAM_A => 10], // Junior
        );

        $rankMismatches = array_filter($violations, fn ($v) => $v['code'] === 'rank_mismatch');
        $this->assertNotEmpty($rankMismatches, 'Expected rank_mismatch when Junior owns requirement phase');
    }

    public function test_rank_mismatch_fires_when_mid_owns_hard_execution(): void
    {
        // 難しい execution → Senior (30) minimum. Mid (20) should violate.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasksWithDifficulty('難しい'), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
            rankLevelByAssignee: [self::TEAM_A => 20], // Mid
        );

        $devMismatch = array_filter(
            $violations,
            fn ($v) => $v['code'] === 'rank_mismatch' && ($v['context']['phase_code'] ?? null) === 'development'
        );
        $this->assertNotEmpty($devMismatch, 'Expected rank_mismatch on 難しい development for Mid assignee');
    }

    public function test_rank_mismatch_silent_when_junior_takes_easy_execution(): void
    {
        // 簡単 execution → Junior (10) is fine. Should NOT fire rank_mismatch
        // on the development phase. The doc phase still goes to a senior here.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_B, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasksWithDifficulty('簡単'), [self::TEAM_A, self::TEAM_B], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
            rankLevelByAssignee: [
                self::TEAM_A => 10, // Junior — fine for 簡単 development
                self::TEAM_B => 30, // Senior — fine for doc/design
            ],
        );

        $this->assertNotContains('rank_mismatch', array_column($violations, 'code'));
    }

    public function test_rank_mismatch_skipped_when_map_empty(): void
    {
        // Empty rank map → the check is skipped entirely. Same input as the
        // hard-execution test, but no rank info supplied.
        $payload = $this->payload([
            ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ['row_no' => 1, 'phase_code' => 'development', 'assignee_id' => self::TEAM_A, 'planned_start' => '2026-05-19', 'planned_end' => '2026-05-20'],
        ]);

        $violations = (new AiScheduleValidator)->validate(
            $payload, $this->tasksWithDifficulty('難しい'), [self::TEAM_A], new WorkingDayCalendar(),
            Carbon::parse('2026-05-01'), Carbon::parse('2026-06-30'),
        );

        $this->assertNotContains('rank_mismatch', array_column($violations, 'code'));
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
