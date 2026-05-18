<?php

namespace App\Services\Scheduling;

use App\Models\ProjectTaskPhaseAssignment;
use Illuminate\Support\Carbon;

/**
 * Computes schedule variance for a phase from its daily progress logs.
 *
 * Spec: SCHEDULE_TRACKING_IMPLEMENTATION_PLAN.md (decoder + formula).
 * The six source-xlsx patterns are exercised by VarianceCalculatorTest.
 *
 * Variance is signed (negative = behind, positive = ahead).
 * Health is a three-tier flag using ratio-of-estimate thresholds.
 */
class VarianceCalculator
{
    public const HEALTH_ON_TRACK_PCT = 0.03;   // ≤ 3 %

    public const HEALTH_AT_RISK_PCT = 0.10;   // ≤ 10 %

    /** Matches AiAutoAssignController::WORKDAY_HOURS. */
    private const WORKDAY_HOURS = 8.0;

    public function __construct(
        private WorkingDayCalendar $calendar,
        private ?Carbon $asOf = null,
    ) {
        $this->asOf = $this->asOf?->copy()->startOfDay() ?? Carbon::today();
    }

    /**
     * Per-phase variance.
     *
     * @return array{
     *     cumulative_progress_hours: float,
     *     cumulative_used_hours: float,
     *     expected_progress_hours: float,
     *     variance_hours: float,
     *     over_delivered_hours: float,
     *     late_hours: float,
     *     schedule_state: string,
     *     health: string,
     *     is_completed: bool,
     * }
     */
    public function forPhase(ProjectTaskPhaseAssignment $phase): array
    {
        $logs = $phase->relationLoaded('progressLogs')
            ? $phase->progressLogs
            : $phase->progressLogs()->get();

        $cumulativeProgress = (float) $logs->sum('progress_hours');
        $cumulativeUsed = (float) $logs->sum('used_hours');
        // Per-log effort overage summed across days. Used by Finance to
        // estimate overtime cost (sum × cost_per_hour). Differs from
        // `over_delivered_hours` (which compares delivery vs estimate)
        // and from `variance_hours` (which compares delivery vs plan-to-date).
        $lateHoursPerLogSum = (float) $logs->sum(function ($log) {
            return max(0.0, (float) $log->used_hours - (float) $log->progress_hours);
        });
        $estimated = (float) $phase->estimated_hours;
        $isCompleted = $phase->actual_end !== null;
        $expectedProgress = $this->expectedProgressForPhase($phase, $estimated);

        // Lateness measured against earned plan; clamp negative late at 0.
        $lateHours = max(0.0, $expectedProgress - $cumulativeProgress);
        $overDelivered = max(0.0, $cumulativeProgress - $estimated);

        if ($isCompleted) {
            // Schedule-only signal. Compare DELIVERY against ESTIMATE; ignore
            // clock time (late_hours already tracks `used − progress` for that).
            // Previously this branch was `estimated − used`, which mixed budget
            // overrun into schedule variance and double-counted late_hours in
            // the project rollup. A phase that delivered exactly to estimate
            // but burned extra clock time is on schedule (variance = 0),
            // over-budget on hours (late_hours > 0) — two distinct signals.
            $varianceHours = $cumulativeProgress - $estimated; // +over, 0 on-target, -short
            if ($varianceHours > 0) {
                $state = 'completed_early';
            } elseif ($varianceHours < 0) {
                $state = 'completed_over_budget';
            } else {
                $state = 'completed_on_time';
            }
        } elseif ($cumulativeProgress <= 0 && $expectedProgress <= 0) {
            $varianceHours = 0.0;
            $state = 'pending';
        } else {
            // In-flight: negative variance when behind earned plan, positive when ahead.
            $varianceHours = $cumulativeProgress - $expectedProgress;
            if (abs($varianceHours) < 1e-6) {
                $state = 'on_track';
            } elseif ($varianceHours > 0) {
                $state = 'ahead';
            } else {
                $state = 'late';
            }
        }

        return [
            'cumulative_progress_hours' => round($cumulativeProgress, 2),
            'cumulative_used_hours' => round($cumulativeUsed, 2),
            'expected_progress_hours' => round($expectedProgress, 2),
            'variance_hours' => round($varianceHours, 2),
            'over_delivered_hours' => round($overDelivered, 2),
            'late_hours' => round($lateHoursPerLogSum, 2),
            'schedule_state' => $state,
            'health' => $this->classifyHealth($varianceHours, $estimated, $state),
            'is_completed' => $isCompleted,
        ];
    }

    /**
     * Aggregates multiple per-phase variance dicts into a single rollup.
     *
     * Used for per-task, per-assignee, and per-project rollups. Pass the
     * `forPhase()` outputs and the corresponding `estimated_hours` per phase.
     *
     * @param  array<int, array{variance_hours: float, cumulative_progress_hours: float, cumulative_used_hours: float, expected_progress_hours: float, over_delivered_hours: float, is_completed: bool}>  $perPhase
     * @param  array<int, float>  $estimatedPerPhase
     */
    public function rollup(array $perPhase, array $estimatedPerPhase): array
    {
        $totalEstimated = array_sum($estimatedPerPhase);
        $totalProgress = 0.0;
        $totalUsed = 0.0;
        $totalExpected = 0.0;
        $totalVariance = 0.0;
        $totalOver = 0.0;
        $totalLate = 0.0;
        $completed = 0;
        $count = count($perPhase);

        foreach ($perPhase as $row) {
            $totalProgress += $row['cumulative_progress_hours'];
            $totalUsed += $row['cumulative_used_hours'];
            $totalExpected += $row['expected_progress_hours'];
            $totalVariance += $row['variance_hours'];
            $totalOver += $row['over_delivered_hours'];
            $totalLate += $row['late_hours'] ?? 0.0;
            if (! empty($row['is_completed'])) {
                $completed++;
            }
        }

        return [
            'total_estimated_hours' => round($totalEstimated, 2),
            'total_progress_hours' => round($totalProgress, 2),
            'total_used_hours' => round($totalUsed, 2),
            'expected_progress_hours' => round($totalExpected, 2),
            'variance_hours' => round($totalVariance, 2),
            'over_delivered_hours' => round($totalOver, 2),
            'late_hours' => round($totalLate, 2),
            'phase_count' => $count,
            'completed_count' => $completed,
            'health' => $this->classifyHealth($totalVariance, $totalEstimated, $completed === $count && $count > 0 ? 'completed_on_time' : 'on_track'),
        ];
    }

    /**
     * Expected cumulative plan delivered by `$this->asOf` for a phase.
     *
     * For single-day atomic phases this is 0 (before start) or `$estimated`
     * (on/after planned_end). For multi-day phases that were hour-packed (so
     * day 1 may carry only a partial slice rather than 8h), we reconstruct the
     * per-day plan from `start_day_hours + planned_start + planned_end +
     * estimated_hours + working calendar`:
     *
     *   day 1        = start_day_hours
     *   middle days  = 8h each
     *   last day     = estimated − start_day_hours − (middle_days × 8h)
     *
     * Legacy rows (start_day_hours = NULL) come from the old "one phase = one
     * working day" scheduler; we treat them as `min(estimated, 8h)` on day 1
     * which matches the old linear-prorating behavior for single-day phases
     * and is a safe default for any multi-day legacy rows.
     */
    private function expectedProgressForPhase(ProjectTaskPhaseAssignment $phase, float $estimated): float
    {
        if ($estimated <= 0 || $phase->planned_start === null || $phase->planned_end === null) {
            return 0.0;
        }

        $start = Carbon::parse($phase->planned_start)->startOfDay();
        $end = Carbon::parse($phase->planned_end)->startOfDay();

        if ($this->asOf->lessThan($start)) {
            return 0.0;
        }
        if ($this->asOf->greaterThanOrEqualTo($end)) {
            return $estimated;
        }

        $totalWorkingDays = $this->countWorkingDays($start, $end);
        if ($totalWorkingDays <= 1) {
            // Single-day phase that's "in flight" today (asOf == planned_start
            // < planned_end). For atomic same-day phases this branch isn't hit
            // because asOf >= planned_end is true; reaching here implies a
            // very short window — default to full estimated as the conservative
            // expectation.
            return $estimated;
        }

        $startDayHours = $phase->start_day_hours !== null
            ? (float) $phase->start_day_hours
            : min($estimated, self::WORKDAY_HOURS);

        $elapsedWorkingDays = $this->countWorkingDays($start, $this->asOf);
        if ($elapsedWorkingDays <= 0) {
            return 0.0;
        }

        if ($elapsedWorkingDays === 1) {
            return $startDayHours;
        }

        // Reconstruct middle-day plan.
        $middleDaysCount = $totalWorkingDays - 2;

        if ($elapsedWorkingDays <= $middleDaysCount + 1) {
            return $startDayHours + ($elapsedWorkingDays - 1) * self::WORKDAY_HOURS;
        }

        // asOf has reached the last day — by definition expected ~= estimated
        // (we already short-circuited asOf >= planned_end above, so this branch
        // is the edge where asOf == planned_end's working day predecessor and
        // calendar arithmetic landed us here).
        return $estimated;
    }

    /**
     * Today-only slice of a phase's planned delivery — the hours that should
     * be done on `$this->asOf` specifically, not cumulative since start. Used
     * by the project rollup card to answer "what's on the plan for TODAY?"
     *
     * Logic mirrors expectedProgressForPhase(): single-day phases contribute
     * their full estimate on that day; multi-day phases contribute
     * start_day_hours on day 1, 8h on middle days, and the remainder on the
     * last day. Non-working days (weekends + holidays) contribute 0 because
     * working-day arithmetic skips them.
     */
    public function todayExpectedForPhase(ProjectTaskPhaseAssignment $phase): float
    {
        $estimated = (float) $phase->estimated_hours;
        if ($estimated <= 0 || $phase->planned_start === null || $phase->planned_end === null) {
            return 0.0;
        }

        $start = Carbon::parse($phase->planned_start)->startOfDay();
        $end = Carbon::parse($phase->planned_end)->startOfDay();

        if ($this->asOf->lessThan($start) || $this->asOf->greaterThan($end)) {
            return 0.0;
        }
        if (! $this->calendar->isWorkingDay($this->asOf)) {
            return 0.0;
        }

        $totalWorkingDays = $this->countWorkingDays($start, $end);
        if ($totalWorkingDays <= 1) {
            // Single-day phase: the whole estimate lands on this day.
            return $estimated;
        }

        $startDayHours = $phase->start_day_hours !== null
            ? (float) $phase->start_day_hours
            : min($estimated, self::WORKDAY_HOURS);

        $elapsedWorkingDays = $this->countWorkingDays($start, $this->asOf);
        if ($elapsedWorkingDays <= 0) {
            return 0.0;
        }
        if ($elapsedWorkingDays === 1) {
            return $startDayHours; // day 1
        }
        if ($elapsedWorkingDays >= $totalWorkingDays) {
            // Last day = estimate − start_day − (middle × 8h).
            $middle = max(0, $totalWorkingDays - 2);

            return max(0.0, $estimated - $startDayHours - $middle * self::WORKDAY_HOURS);
        }

        return self::WORKDAY_HOURS; // middle day
    }

    private function countWorkingDays(Carbon $from, Carbon $to): int
    {
        if ($to->lessThan($from)) {
            return 0;
        }
        $count = 0;
        $cursor = $from->copy()->startOfDay();
        $stop = $to->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($stop)) {
            if ($this->calendar->isWorkingDay($cursor)) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    private function classifyHealth(float $variance, float $estimated, string $state): string
    {
        if ($estimated <= 0 || $state === 'pending') {
            return 'on_track';
        }

        // Positive variance = ahead of plan. PMs don't need a warning badge for
        // "delivered more than planned" — that's not a risk. Only negative
        // variance gets graded into at_risk / slipping tiers.
        if ($variance >= 0) {
            return 'on_track';
        }

        $ratio = abs($variance) / $estimated;

        if ($ratio <= self::HEALTH_ON_TRACK_PCT) {
            return 'on_track';
        }
        if ($ratio <= self::HEALTH_AT_RISK_PCT) {
            return 'at_risk';
        }

        return 'slipping';
    }
}
