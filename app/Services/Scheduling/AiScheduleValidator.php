<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Carbon;

/**
 * Validates an AiSchedulePayload against the source tasks, project window,
 * team membership, and calendar. Returns a list of structured violations so
 * the caller can either retry with AI (sending the violations back as a
 * correction prompt) or fall back to the deterministic scheduler.
 *
 * Pure — no DB, no model loading. All inputs are passed in.
 */
class AiScheduleValidator
{
    /** Duration may legitimately stretch or compress this much vs ceil(hours/hpd). */
    public const DURATION_TOLERANCE = 0.5;

    /** Hard floor on hours_per_day to avoid divide-by-tiny-number duration sanity. */
    public const HOURS_PER_DAY_FLOOR = 1.0;

    /** Default daily capacity when an employee has no explicit override. */
    public const DEFAULT_HOURS_PER_DAY = 8.0;

    /**
     * @param array<int, array{row_no:int, phases:array<int, array{code:string, hours:float}>}> $tasks
     * @param array<int, string> $teamIds
     * @return array<int, array{code:string, message:string, context:array<string,mixed>}>
     */
    public function validate(
        AiSchedulePayload $payload,
        array $tasks,
        array $teamIds,
        WorkingDayCalendar $calendar,
        Carbon $windowStart,
        Carbon $effectiveEnd,
    ): array {
        $violations = [];
        $teamSet = array_flip($teamIds);

        // Index tasks by row for fast lookup.
        $tasksByRow = [];
        foreach ($tasks as $t) {
            $tasksByRow[$t['row_no']] = $t;
        }

        // Track per-assignee intervals for overlap detection. Each entry:
        // [employee_id => [['start' => Carbon, 'end' => Carbon, 'row_no' => int, 'phase' => string], ...]]
        $intervals = [];

        // 1. Completeness — every (row_no × active_phase) must have an entry.
        foreach ($tasks as $t) {
            foreach ($t['phases'] as $phase) {
                if (! isset($payload->assignmentsByRowPhase[$t['row_no']][$phase['code']])) {
                    $violations[] = [
                        'code'    => 'missing_assignment',
                        'message' => "No assignment returned for row {$t['row_no']}, phase {$phase['code']}.",
                        'context' => ['row_no' => $t['row_no'], 'phase_code' => $phase['code']],
                    ];
                }
            }
        }

        // 2. Per-entry checks.
        foreach ($payload->assignmentsByRowPhase as $rowNo => $byPhase) {
            $task = $tasksByRow[$rowNo] ?? null;
            if ($task === null) {
                foreach ($byPhase as $phase => $_) {
                    $violations[] = [
                        'code'    => 'unknown_row',
                        'message' => "Assignment refers to row_no {$rowNo}, which is not in the task list.",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phase],
                    ];
                }
                continue;
            }
            $phasesByCode = [];
            foreach ($task['phases'] as $p) {
                $phasesByCode[$p['code']] = $p;
            }

            foreach ($byPhase as $phaseCode => $entry) {
                $phaseDef = $phasesByCode[$phaseCode] ?? null;
                if ($phaseDef === null) {
                    $violations[] = [
                        'code'    => 'unknown_phase',
                        'message' => "Phase {$phaseCode} is not in row {$rowNo}'s active phases.",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode],
                    ];
                    continue;
                }

                $assigneeId = $entry['assignee_id'];
                if (! isset($teamSet[$assigneeId])) {
                    $violations[] = [
                        'code'    => 'unknown_assignee',
                        'message' => "Assignee {$assigneeId} is not on the project team (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'assignee_id' => $assigneeId],
                    ];
                    // Don't run further per-entry checks since they depend on a valid assignee.
                    continue;
                }

                $start = Carbon::parse($entry['planned_start'])->startOfDay();
                $end   = Carbon::parse($entry['planned_end'])->startOfDay();

                if ($end->lessThan($start)) {
                    $violations[] = [
                        'code'    => 'inverted_range',
                        'message' => "planned_end before planned_start (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'planned_start' => $entry['planned_start'], 'planned_end' => $entry['planned_end']],
                    ];
                    continue;
                }

                if ($start->lessThan($windowStart) || $end->greaterThan($effectiveEnd)) {
                    $violations[] = [
                        'code'    => 'out_of_window',
                        'message' => "Range {$entry['planned_start']}..{$entry['planned_end']} is outside the project window {$windowStart->toDateString()}..{$effectiveEnd->toDateString()} (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'window_start' => $windowStart->toDateString(), 'window_end' => $effectiveEnd->toDateString()],
                    ];
                }

                if (! $calendar->isWorkingDay($start, $assigneeId)) {
                    $violations[] = [
                        'code'    => 'non_working_start',
                        'message' => "planned_start {$entry['planned_start']} is not a working day for assignee (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'date' => $entry['planned_start']],
                    ];
                }
                if (! $calendar->isWorkingDay($end, $assigneeId)) {
                    $violations[] = [
                        'code'    => 'non_working_end',
                        'message' => "planned_end {$entry['planned_end']} is not a working day for assignee (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'date' => $entry['planned_end']],
                    ];
                }

                // Duration sanity.
                $hpd = max(self::HOURS_PER_DAY_FLOOR, $payload->hoursPerDayFor($assigneeId, self::DEFAULT_HOURS_PER_DAY));
                $expectedDays = max(1, (int) ceil(((float) $phaseDef['hours']) / $hpd));
                $actualDays   = $calendar->workingDaysBetween($start, $end, $assigneeId);
                $minDays = max(1, (int) floor($expectedDays * (1 - self::DURATION_TOLERANCE)));
                $maxDays = max($minDays, (int) ceil($expectedDays * (1 + self::DURATION_TOLERANCE)));
                if ($actualDays < $minDays || $actualDays > $maxDays) {
                    $violations[] = [
                        'code'    => 'duration_out_of_tolerance',
                        'message' => "Duration {$actualDays} working days is outside the tolerated range {$minDays}..{$maxDays} for {$phaseDef['hours']}h at {$hpd}h/day (row {$rowNo}, phase {$phaseCode}).",
                        'context' => [
                            'row_no' => $rowNo, 'phase_code' => $phaseCode,
                            'actual_days' => $actualDays, 'expected_days' => $expectedDays,
                            'hours_per_day' => $hpd, 'estimated_hours' => $phaseDef['hours'],
                        ],
                    ];
                }

                $intervals[$assigneeId][] = [
                    'start'  => $start,
                    'end'    => $end,
                    'row_no' => $rowNo,
                    'phase'  => $phaseCode,
                ];
            }

            // 3. Phase order — within a task, sorted by phase_order, planned_start non-decreasing.
            $ordered = [];
            foreach ($task['phases'] as $p) {
                if (isset($payload->assignmentsByRowPhase[$rowNo][$p['code']])) {
                    $entry = $payload->assignmentsByRowPhase[$rowNo][$p['code']];
                    $ordered[] = [
                        'order' => $p['order'] ?? 0,
                        'code'  => $p['code'],
                        'start' => Carbon::parse($entry['planned_start']),
                    ];
                }
            }
            usort($ordered, fn ($a, $b) => $a['order'] <=> $b['order']);
            for ($i = 1; $i < count($ordered); $i++) {
                if ($ordered[$i]['start']->lessThan($ordered[$i - 1]['start'])) {
                    $violations[] = [
                        'code'    => 'phase_order_violation',
                        'message' => "Phase {$ordered[$i]['code']} starts before earlier phase {$ordered[$i - 1]['code']} in row {$rowNo}.",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $ordered[$i]['code'], 'previous_phase' => $ordered[$i - 1]['code']],
                    ];
                }
            }
        }

        // 4. Double-booking.
        foreach ($intervals as $assigneeId => $list) {
            usort($list, fn ($a, $b) => $a['start']->timestamp <=> $b['start']->timestamp);
            for ($i = 1; $i < count($list); $i++) {
                $prev = $list[$i - 1];
                $cur  = $list[$i];
                if ($cur['start']->lessThanOrEqualTo($prev['end'])) {
                    $violations[] = [
                        'code'    => 'double_booking',
                        'message' => "Assignee {$assigneeId} has overlapping ranges: row {$prev['row_no']}/{$prev['phase']} ({$prev['start']->toDateString()}..{$prev['end']->toDateString()}) and row {$cur['row_no']}/{$cur['phase']} ({$cur['start']->toDateString()}..{$cur['end']->toDateString()}).",
                        'context' => [
                            'assignee_id' => $assigneeId,
                            'a' => ['row_no' => $prev['row_no'], 'phase_code' => $prev['phase']],
                            'b' => ['row_no' => $cur['row_no'],  'phase_code' => $cur['phase']],
                        ],
                    ];
                }
            }
        }

        return $violations;
    }
}
