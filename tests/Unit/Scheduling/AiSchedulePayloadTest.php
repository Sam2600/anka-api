<?php

namespace Tests\Unit\Scheduling;

use App\Exceptions\InvalidAiScheduleException;
use App\Services\Scheduling\AiSchedulePayload;
use PHPUnit\Framework\TestCase;

class AiSchedulePayloadTest extends TestCase
{
    public function test_parses_plain_json(): void
    {
        $json = json_encode([
            'calendar'    => ['skip_weekends' => true, 'recurring_holidays' => [['month' => 1, 'day' => 1, 'reason' => 'NY']], 'blocked_dates' => [['date' => '2026-07-04', 'reason' => 'IDay']]],
            'capacity'    => [['employee_id' => 'emp-1', 'hours_per_day' => 4, 'reason' => 'shared']],
            'assignments' => [
                ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => 'emp-1', 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ],
        ]);

        $p = AiSchedulePayload::fromRaw($json);

        $this->assertTrue($p->skipWeekends);
        $this->assertCount(1, $p->recurringHolidays);
        $this->assertSame(1, $p->recurringHolidays[0]['month']);
        $this->assertCount(1, $p->blockedDates);
        $this->assertSame('2026-07-04', $p->blockedDates[0]['date']);
        $this->assertSame(4.0, $p->capacityByEmployeeId['emp-1']['hours_per_day']);
        $this->assertSame('emp-1', $p->assignmentsByRowPhase[1]['requirement']['assignee_id']);
    }

    public function test_strips_fenced_json(): void
    {
        $inner = json_encode([
            'assignments' => [
                ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => 'emp-1', 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ],
        ]);
        $fenced = "```json\n{$inner}\n```";

        $p = AiSchedulePayload::fromRaw($fenced);

        $this->assertSame('emp-1', $p->assignmentsByRowPhase[1]['requirement']['assignee_id']);
    }

    public function test_hours_per_day_falls_back_to_default(): void
    {
        $p = AiSchedulePayload::fromRaw(json_encode([
            'assignments' => [
                ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => 'emp-2', 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
            ],
        ]));

        $this->assertSame(8.0, $p->hoursPerDayFor('emp-2'));
        $this->assertSame(8.0, $p->hoursPerDayFor('emp-2', 8.0));
    }

    public function test_throws_when_not_json(): void
    {
        $this->expectException(InvalidAiScheduleException::class);
        AiSchedulePayload::fromRaw('not json at all');
    }

    public function test_throws_when_assignments_missing(): void
    {
        $this->expectException(InvalidAiScheduleException::class);
        AiSchedulePayload::fromRaw(json_encode(['calendar' => []]));
    }

    public function test_throws_when_assignments_empty(): void
    {
        $this->expectException(InvalidAiScheduleException::class);
        AiSchedulePayload::fromRaw(json_encode(['assignments' => []]));
    }

    public function test_silently_drops_malformed_entries(): void
    {
        $p = AiSchedulePayload::fromRaw(json_encode([
            'assignments' => [
                ['row_no' => 1, 'phase_code' => 'requirement', 'assignee_id' => 'emp-1', 'planned_start' => '2026-05-18', 'planned_end' => '2026-05-18'],
                ['row_no' => 2, 'phase_code' => 'development'], // missing fields
                ['row_no' => 3, 'phase_code' => 'requirement', 'assignee_id' => 'emp-1', 'planned_start' => 'NOT-A-DATE', 'planned_end' => '2026-05-18'],
            ],
        ]));

        $this->assertArrayHasKey(1, $p->assignmentsByRowPhase);
        $this->assertArrayNotHasKey(2, $p->assignmentsByRowPhase);
        $this->assertArrayNotHasKey(3, $p->assignmentsByRowPhase);
    }
}
