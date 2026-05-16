<?php

namespace App\Services\Scheduling;

use App\Exceptions\InvalidAiScheduleException;

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
            $day   = (int) $entry['day'];
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                continue;
            }
            $self->recurringHolidays[] = [
                'month'  => $month,
                'day'    => $day,
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
                'date'   => $date,
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
                'reason'        => isset($entry['reason']) ? (string) $entry['reason'] : null,
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
            $rowNo  = (int) $entry['row_no'];
            $phase  = (string) $entry['phase_code'];
            $start  = (string) $entry['planned_start'];
            $end    = (string) $entry['planned_end'];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                continue;
            }
            $self->assignmentsByRowPhase[$rowNo][$phase] = [
                'assignee_id'   => (string) $entry['assignee_id'],
                'planned_start' => $start,
                'planned_end'   => $end,
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
}
