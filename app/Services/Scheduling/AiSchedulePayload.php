<?php

namespace App\Services\Scheduling;

use App\Exceptions\InvalidAiScheduleException;
use Illuminate\Support\Carbon;

/**
 * Typed DTO + parser for the JSON Claude returns from the AI-driven task
 * scheduler. The parser is forgiving about cosmetic noise (```json fences,
 * trailing whitespace) but strict about structure — anything missing top-level
 * keys or with the wrong type for `assignments` throws so the controller can
 * short-circuit to the deterministic fallback without burning a retry.
 */
class AiSchedulePayload
{
    /** @var array<int, array{month:int, day:int, reason:?string}> */
    public array $recurringHolidays = [];

    /** @var array<int, array{date:string, reason:?string}> */
    public array $blockedDates = [];

    /** @var array<string, array{hours_per_day:float, reason:?string}> employee_id => capacity */
    public array $capacityByEmployeeId = [];

    /** @var array<int, array<string, array{assignee_id:string, planned_start:string, planned_end:string}>> row_no => phase_code => entry */
    public array $assignmentsByRowPhase = [];

    public bool $skipWeekends = true;

    public static function fromRaw(string $text): self
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new InvalidAiScheduleException('AI response was not valid JSON.');
        }

        $self = new self;

        // Calendar block is optional — missing means "use DB defaults only".
        $calendar = $decoded['calendar'] ?? [];
        if (! is_array($calendar)) {
            throw new InvalidAiScheduleException('calendar must be an object.');
        }
        if (array_key_exists('skip_weekends', $calendar)) {
            $self->skipWeekends = (bool) $calendar['skip_weekends'];
        }
        foreach ($calendar['recurring_holidays'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['month'], $entry['day'])) {
                continue;
            }
            $month = (int) $entry['month'];
            $day = (int) $entry['day'];
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                continue;
            }
            $self->recurringHolidays[] = [
                'month' => $month,
                'day' => $day,
                'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null,
            ];
        }
        foreach ($calendar['blocked_dates'] ?? [] as $entry) {
            if (! is_array($entry) || empty($entry['date'])) {
                continue;
            }
            $date = (string) $entry['date'];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $self->blockedDates[] = [
                'date' => $date,
                'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null,
            ];
        }

        // Capacity block is optional.
        $capacity = $decoded['capacity'] ?? [];
        if (! is_array($capacity)) {
            throw new InvalidAiScheduleException('capacity must be an array.');
        }
        foreach ($capacity as $entry) {
            if (! is_array($entry) || empty($entry['employee_id']) || ! isset($entry['hours_per_day'])) {
                continue;
            }
            $hours = (float) $entry['hours_per_day'];
            if ($hours <= 0) {
                continue;
            }
            $self->capacityByEmployeeId[(string) $entry['employee_id']] = [
                'hours_per_day' => $hours,
                'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null,
            ];
        }

        // Assignments are mandatory.
        $assignments = $decoded['assignments'] ?? null;
        if (! is_array($assignments)) {
            throw new InvalidAiScheduleException('assignments must be an array.');
        }
        foreach ($assignments as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (! isset($entry['row_no'], $entry['phase_code'], $entry['assignee_id'], $entry['planned_start'], $entry['planned_end'])) {
                continue;
            }
            $rowNo = (int) $entry['row_no'];
            $phase = (string) $entry['phase_code'];
            $start = (string) $entry['planned_start'];
            $end = (string) $entry['planned_end'];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                continue;
            }
            $self->assignmentsByRowPhase[$rowNo][$phase] = [
                'assignee_id' => (string) $entry['assignee_id'],
                'planned_start' => $start,
                'planned_end' => $end,
            ];
        }

        if (empty($self->assignmentsByRowPhase)) {
            throw new InvalidAiScheduleException('assignments was empty after parsing.');
        }

        return $self;
    }

    public function hoursPerDayFor(string $employeeId, float $default = 8.0): float
    {
        return $this->capacityByEmployeeId[$employeeId]['hours_per_day'] ?? $default;
    }

    /**
     * Clamp every assignment's planned_end so the working-days span matches
     * ceil(estimated_hours / hours_per_day) within DURATION_TOLERANCE. Mutates
     * the payload. Returns count of phases adjusted.
     *
     * Used as a post-AI fixer when the AI returns durations far from the
     * estimate — typically because it tried to compress or stretch to fit
     * around overlaps. We pull the duration back to the expected value
     * starting from the AI's chosen planned_start, which preserves the AI's
     * sequencing intent while satisfying the duration validator.
     *
     * @param  array<int, array{row_no:int, phases:array<int, array{code:string, hours:float}>}>  $tasks
     */
    public function clampDurationOutliers(
        WorkingDayCalendar $calendar,
        array $tasks,
        float $defaultHoursPerDay = 8.0,
        float $tolerance = 0.5,
    ): int {
        // Index phase hours by (row, phase) for quick lookup.
        $hoursByPair = [];
        foreach ($tasks as $t) {
            foreach ($t['phases'] as $p) {
                $hoursByPair[$t['row_no']][$p['code']] = (float) $p['hours'];
            }
        }

        $clamped = 0;
        foreach ($this->assignmentsByRowPhase as $rowNo => &$byPhase) {
            foreach ($byPhase as $phaseCode => &$entry) {
                $hours = $hoursByPair[$rowNo][$phaseCode] ?? null;
                if ($hours === null || $hours <= 0) {
                    continue;
                }
                $hpd = max(1.0, $this->hoursPerDayFor($entry['assignee_id'], $defaultHoursPerDay));
                $expectedDays = max(1, (int) ceil($hours / $hpd));

                $start = Carbon::parse($entry['planned_start'])->startOfDay();
                $end = Carbon::parse($entry['planned_end'])->startOfDay();
                $actualDays = $calendar->workingDaysBetween($start, $end, $entry['assignee_id']);

                $minDays = max(1, (int) floor($expectedDays * (1 - $tolerance)));
                $maxDays = max($minDays, (int) ceil($expectedDays * (1 + $tolerance)));
                if ($actualDays >= $minDays && $actualDays <= $maxDays) {
                    continue;
                }

                // Reset to exactly expectedDays from start.
                $newEnd = $calendar->addWorkingDays($start, $expectedDays - 1, $entry['assignee_id']);
                $entry['planned_end'] = $newEnd->toDateString();
                $clamped++;
            }
            unset($entry);
        }
        unset($byPhase);

        return $clamped;
    }

    /**
     * Ensure each task row's phases run in `order` ascending — phase N's
     * planned_start must be >= phase N-1's planned_start. When the overlap
     * resolver shifts phases forward to clear double-bookings, it can land a
     * later-order phase before an earlier one in the same row; this fixer
     * walks each row in order and shifts any out-of-order phase forward to
     * the previous phase's start.
     *
     * Preserves duration in calendar days. Refuses shifts past $effectiveEnd
     * (validator will flag the residual as phase_order_violation, treated as
     * soft by the controller after the fixer ran).
     *
     * @param  array<int, array{row_no:int, phases:array<int, array{code:string, order?:int}>}>  $tasks
     */
    public function enforcePhaseOrderWithinRows(
        WorkingDayCalendar $calendar,
        array $tasks,
        Carbon $effectiveEnd,
    ): int {
        $shifted = 0;
        foreach ($tasks as $t) {
            $phases = $t['phases'];
            usort($phases, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

            $prevStart = null;
            foreach ($phases as $phase) {
                $entry = $this->assignmentsByRowPhase[$t['row_no']][$phase['code']] ?? null;
                if ($entry === null) {
                    continue;
                }
                $start = Carbon::parse($entry['planned_start'])->startOfDay();
                $end = Carbon::parse($entry['planned_end'])->startOfDay();

                if ($prevStart !== null && $start->lessThan($prevStart)) {
                    $duration = $start->diffInDays($end);
                    $newStart = $calendar->nextWorkingDay($prevStart->copy(), $entry['assignee_id']);
                    $newEnd = $newStart->copy()->addDays($duration);
                    if (! $calendar->isWorkingDay($newEnd, $entry['assignee_id'])) {
                        $newEnd = $calendar->previousWorkingDay($newEnd, $entry['assignee_id']);
                    }
                    if ($newEnd->lessThan($newStart)) {
                        $newEnd = $calendar->nextWorkingDay($newStart, $entry['assignee_id']);
                    }
                    if ($newEnd->greaterThan($effectiveEnd)) {
                        $prevStart = $start;

                        continue;
                    }
                    $this->assignmentsByRowPhase[$t['row_no']][$phase['code']]['planned_start'] = $newStart->toDateString();
                    $this->assignmentsByRowPhase[$t['row_no']][$phase['code']]['planned_end'] = $newEnd->toDateString();
                    $start = $newStart;
                    $shifted++;
                }
                $prevStart = $start;
            }
        }

        return $shifted;
    }

    /**
     * Snap any planned_start landing on a non-working day forward, and any
     * planned_end landing on a non-working day backward. Mutates the payload
     * in place. Returns the count of dates changed (for INFO logging).
     *
     * Safety properties:
     *   - never changes an assignee
     *   - never extends total workload — both ends snap inward, so the
     *     interval can only become shorter or stay the same
     *   - if snap collapses end < start (e.g. a 1-day phase on a Saturday),
     *     we re-extend by one working day so the interval remains valid
     */
    public function snapDatesToWorkingDays(WorkingDayCalendar $calendar): int
    {
        $snapped = 0;
        foreach ($this->assignmentsByRowPhase as $rowNo => &$byPhase) {
            foreach ($byPhase as $phaseCode => &$entry) {
                $assigneeId = $entry['assignee_id'];
                $start = Carbon::parse($entry['planned_start'])->startOfDay();
                $end = Carbon::parse($entry['planned_end'])->startOfDay();

                if (! $calendar->isWorkingDay($start, $assigneeId)) {
                    $newStart = $calendar->nextWorkingDay($start, $assigneeId);
                    if (! $newStart->equalTo($start)) {
                        $entry['planned_start'] = $newStart->toDateString();
                        $start = $newStart;
                        $snapped++;
                    }
                }

                if (! $calendar->isWorkingDay($end, $assigneeId)) {
                    $newEnd = $calendar->previousWorkingDay($end, $assigneeId);
                    if (! $newEnd->equalTo($end)) {
                        $entry['planned_end'] = $newEnd->toDateString();
                        $end = $newEnd;
                        $snapped++;
                    }
                }

                if ($end->lessThan($start)) {
                    $entry['planned_end'] = $calendar->nextWorkingDay($start, $assigneeId)->toDateString();
                    $snapped++;
                }
            }
            unset($entry);
        }
        unset($byPhase);

        return $snapped;
    }

    /**
     * Resolve overlapping intervals on the same assignee by shifting the
     * later interval forward to the next working day after the earlier one's
     * end. Mutates the payload in place. Returns count of intervals shifted.
     *
     * Safety properties:
     *   - never reassigns work (the assignee_id stays the same)
     *   - never shrinks or extends an interval — calendar-day duration is preserved
     *   - never pushes past $effectiveEnd — refuses out-of-window shifts and
     *     leaves the offending interval untouched (validator will surface it)
     */
    public function resolveAssigneeOverlaps(
        WorkingDayCalendar $calendar,
        Carbon $effectiveEnd,
    ): int {
        // Flatten to (assigneeId, rowNo, phaseCode, start, end) tuples.
        $intervals = [];
        foreach ($this->assignmentsByRowPhase as $rowNo => $byPhase) {
            foreach ($byPhase as $phaseCode => $entry) {
                $intervals[] = [
                    'assignee_id' => $entry['assignee_id'],
                    'row_no' => $rowNo,
                    'phase_code' => $phaseCode,
                    'start' => Carbon::parse($entry['planned_start'])->startOfDay(),
                    'end' => Carbon::parse($entry['planned_end'])->startOfDay(),
                ];
            }
        }

        // Group by assignee, sort by start.
        $byAssignee = [];
        foreach ($intervals as $iv) {
            $byAssignee[$iv['assignee_id']][] = $iv;
        }

        $shifted = 0;
        foreach ($byAssignee as $assigneeId => &$list) {
            usort($list, fn ($a, $b) => $a['start']->timestamp <=> $b['start']->timestamp);

            $tailEnd = null;
            foreach ($list as &$iv) {
                if ($tailEnd === null || $iv['start']->greaterThan($tailEnd)) {
                    $tailEnd = $iv['end'];

                    continue;
                }

                $duration = $iv['start']->diffInDays($iv['end']);
                $newStart = $calendar->nextWorkingDay($tailEnd->copy()->addDay(), $assigneeId);
                $newEnd = $newStart->copy()->addDays($duration);

                if (! $calendar->isWorkingDay($newEnd, $assigneeId)) {
                    $newEnd = $calendar->previousWorkingDay($newEnd, $assigneeId);
                }
                if ($newEnd->lessThan($newStart)) {
                    $newEnd = $calendar->nextWorkingDay($newStart, $assigneeId);
                }

                if ($newEnd->greaterThan($effectiveEnd)) {
                    // Out of window — refuse the shift; advance tailEnd
                    // anyway so we keep walking the remaining intervals.
                    $tailEnd = $iv['end']->greaterThan($tailEnd) ? $iv['end'] : $tailEnd;

                    continue;
                }

                // Apply the shift in place on the payload.
                $this->assignmentsByRowPhase[$iv['row_no']][$iv['phase_code']]['planned_start'] = $newStart->toDateString();
                $this->assignmentsByRowPhase[$iv['row_no']][$iv['phase_code']]['planned_end'] = $newEnd->toDateString();
                $iv['start'] = $newStart;
                $iv['end'] = $newEnd;
                $tailEnd = $newEnd;
                $shifted++;
            }
            unset($iv);
        }
        unset($list);

        return $shifted;
    }
}
