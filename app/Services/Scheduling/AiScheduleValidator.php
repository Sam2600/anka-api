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
     * Hours an assignee can absorb above their stored allocated_hours before
     * the validator complains. Mirrors DURATION_TOLERANCE — small overshoots
     * are tolerated, big ones are not.
     */
    public const ALLOCATION_TOLERANCE = 0.10;

    /**
     * Approximate calendar-days-per-engagement-month used to convert a
     * member's `engagement_months` into a maximum span from their earliest
     * planned_start to their latest planned_end. 31 keeps it generous —
     * we'd rather miss a borderline case than over-flag legitimate plans.
     */
    public const ENGAGEMENT_DAYS_PER_MONTH = 31;

    /** Tolerance for engagement-window span (same idea as ALLOCATION_TOLERANCE). */
    public const ENGAGEMENT_WINDOW_TOLERANCE = 0.10;

    /**
     * Per-phase list of `capacity_role` values eligible to be assigned that
     * phase. Order matters — earlier entries are preferred when assigning
     * (a doc phase goes to pm first, falls through to backend/frontend only
     * if pm is unavailable or over-cap).
     *
     * - Doc/design phases: pm leads, but a senior dev can document if the
     *   team has no pm or pm is saturated.
     * - Development: backend or frontend, no fallback (managers don't code).
     * - Testing: qa primary; devs self-test when qa is over-capacity.
     *
     * `design` (UI designer) is intentionally omitted from doc/dev pools —
     * designers do design work, not generic documentation or coding. If a
     * project has design-phase xlsx rows, those would route to design via a
     * separate entry (none in PHASE_CATALOG today).
     *
     * @var array<string, array<int, string>>
     */
    public const PHASE_CAPACITY_ROLES = [
        'requirement' => ['pm', 'backend', 'frontend'],
        'system_arch' => ['pm', 'backend', 'frontend'],
        'basic_doc' => ['pm', 'backend', 'frontend'],
        'detail_doc' => ['pm', 'backend', 'frontend'],
        'development' => ['backend', 'frontend'],
        'unit_test' => ['qa', 'backend', 'frontend'],
        'combine_test' => ['qa', 'backend', 'frontend'],
        'system_test' => ['qa', 'backend', 'frontend'],
    ];

    /**
     * Doc/design phases — anything in this set is treated as documentation
     * work for the rank-fit check, regardless of difficulty. Mirrors the
     * `$designPhases` list in AiAutoAssignController.
     */
    private const DESIGN_PHASES = ['requirement', 'system_arch', 'basic_doc', 'detail_doc'];

    /**
     * Minimum acceptable rank LEVEL (ranks.level) per phase category. Mirrors
     * the rank-fit guidance in the system prompt's rule 5:
     *
     *   - Doc/design phases require Senior (level 30) or higher — Juniors
     *     and Mids shouldn't own specs/architecture even when the higher-
     *     ranked pool is saturated. Quality risk is too high.
     *
     *   - Execution phases (development, unit_test, combine_test,
     *     system_test) scale by the task's `difficulty` column:
     *       簡単 (easy)   → Junior OK   (level 10)
     *       普通 (normal) → Mid+        (level 20)
     *       難しい (hard) → Senior+     (level 30)
     *
     * This check fires as SOFT — same severity as over_allocation — because
     * rank-fit is a PREFER rule in the prompt, not HARD. A team without
     * higher-ranked engineers will legitimately stretch DOWN; we surface
     * those stretches as warnings so the operator can see the quality risk,
     * not as retry triggers that would block honest schedules.
     */
    public const MIN_RANK_LEVEL_FOR_DESIGN = 30;

    public const MIN_RANK_LEVEL_FOR_EXECUTION = [
        '簡単' => 10,
        '普通' => 20,
        '難しい' => 30,
    ];

    /**
     * Resolve the minimum acceptable rank level for a (phase, difficulty)
     * pair. Falls back to Mid (20) for unrecognised difficulty values — the
     * xlsx parser already coerces unknown strings to 普通, so this is
     * defence in depth rather than a real branch.
     */
    public static function minRankLevelFor(string $phaseCode, ?string $difficulty): int
    {
        if (in_array($phaseCode, self::DESIGN_PHASES, true)) {
            return self::MIN_RANK_LEVEL_FOR_DESIGN;
        }

        return self::MIN_RANK_LEVEL_FOR_EXECUTION[$difficulty ?? '普通'] ?? 20;
    }

    /**
     * Slop allowed when comparing summed assigned hours against the
     * monthly_allocation budget. Apportionment by working-day overlap is
     * approximate; 0.5h tolerance absorbs rounding without letting real
     * over-allocation slip past.
     */
    public const MONTHLY_BUDGET_TOLERANCE_HOURS = 0.5;

    /**
     * @param  array<int, array{row_no:int, phases:array<int, array{code:string, hours:float}>}>  $tasks
     * @param  array<int, string>  $teamIds
     * @param  array<string, float>  $allocatedHoursByAssignee  employee_id => capacity ceiling. Empty/omitted skips the check.
     * @param  array<string, float>  $engagementMonthsByAssignee  employee_id => contracted months. Empty/omitted skips the engagement-window check.
     * @param  array<string, string>  $capacityRoleByAssignee  employee_id => capacity_role code (backend, frontend, pm, qa, design). Empty/omitted skips the role-mismatch check.
     * @param  array<string, int>  $rankLevelByAssignee  employee_id => ranks.level (Junior 10, Mid 20, Senior 30, Lead 40). Empty/omitted skips the rank-mismatch check.
     * @param  array<string, array<int, float>>  $monthlyAllocationByAssignee  employee_id => allocation array, indexed from team_start_date. Empty/omitted skips zero-month + monthly-budget checks.
     * @param  array<string, string>  $teamStartByAssignee  employee_id => team_start_date (YYYY-MM-DD). Required when monthlyAllocationByAssignee is supplied.
     * @param  array<string, float>  $workableHoursByAssignee  employee_id => workable_hours/month. Required when monthlyAllocationByAssignee is supplied (used to compute the budget).
     * @return array<int, array{code:string, message:string, context:array<string,mixed>}>
     */
    public function validate(
        AiSchedulePayload $payload,
        array $tasks,
        array $teamIds,
        WorkingDayCalendar $calendar,
        Carbon $windowStart,
        Carbon $effectiveEnd,
        array $allocatedHoursByAssignee = [],
        array $engagementMonthsByAssignee = [],
        array $capacityRoleByAssignee = [],
        array $rankLevelByAssignee = [],
        array $monthlyAllocationByAssignee = [],
        array $teamStartByAssignee = [],
        array $workableHoursByAssignee = [],
    ): array {
        $violations = [];
        $teamSet = array_flip($teamIds);

        // Per-assignee running total of estimated phase hours assigned.
        // Compared against $allocatedHoursByAssignee at the end of the walk.
        $assignedHoursByAssignee = [];

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
                        'code' => 'missing_assignment',
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
                        'code' => 'unknown_row',
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
                        'code' => 'unknown_phase',
                        'message' => "Phase {$phaseCode} is not in row {$rowNo}'s active phases.",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode],
                    ];

                    continue;
                }

                $assigneeId = $entry['assignee_id'];
                if (! isset($teamSet[$assigneeId])) {
                    $violations[] = [
                        'code' => 'unknown_assignee',
                        'message' => "Assignee {$assigneeId} is not on the project team (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'assignee_id' => $assigneeId],
                    ];

                    // Don't run further per-entry checks since they depend on a valid assignee.
                    continue;
                }

                // capacity_role compatibility — pm can't QA, qa can't develop, etc.
                if (! empty($capacityRoleByAssignee) && isset(self::PHASE_CAPACITY_ROLES[$phaseCode])) {
                    $assigneeRole = $capacityRoleByAssignee[$assigneeId] ?? null;
                    $eligibleRoles = self::PHASE_CAPACITY_ROLES[$phaseCode];
                    if ($assigneeRole !== null && ! in_array($assigneeRole, $eligibleRoles, true)) {
                        $violations[] = [
                            'code' => 'capacity_role_mismatch',
                            'message' => "Assignee {$assigneeId} has capacity_role '{$assigneeRole}' but phase "
                                ."{$phaseCode} is restricted to ".implode('|', $eligibleRoles)
                                ." (row {$rowNo}). Reassign to a teammate whose capacity_role is in the eligible list.",
                            'context' => [
                                'row_no' => $rowNo,
                                'phase_code' => $phaseCode,
                                'assignee_id' => $assigneeId,
                                'assignee_role' => $assigneeRole,
                                'eligible_roles' => $eligibleRoles,
                            ],
                        ];
                    }
                }

                // Rank-fit (SOFT). Surfaces quality risk when the assigner had
                // to stretch DOWN past the rank floor for this (phase,
                // difficulty) pair — e.g. a Junior owning detail_doc, or a
                // Mid owning a 難しい development phase. Always classified as
                // soft in the controller, so an honest schedule on a tight
                // team still persists; the operator sees the warning and can
                // judge whether to widen the team's seniority mix.
                if (! empty($rankLevelByAssignee)) {
                    $assigneeLevel = $rankLevelByAssignee[$assigneeId] ?? null;
                    if ($assigneeLevel !== null) {
                        $difficulty = $task['difficulty'] ?? '普通';
                        $minLevel = self::minRankLevelFor($phaseCode, $difficulty);
                        if ($assigneeLevel < $minLevel) {
                            $rankCodeByLevel = [10 => 'Junior', 20 => 'Mid', 30 => 'Senior', 40 => 'Lead'];
                            $actual = $rankCodeByLevel[$assigneeLevel] ?? "level {$assigneeLevel}";
                            $required = $rankCodeByLevel[$minLevel] ?? "level {$minLevel}";
                            $phaseLabel = in_array($phaseCode, self::DESIGN_PHASES, true)
                                ? "doc/design phase {$phaseCode}"
                                : "{$difficulty} execution phase {$phaseCode}";
                            $violations[] = [
                                'code' => 'rank_mismatch',
                                'message' => "Assignee {$assigneeId} is {$actual} but {$phaseLabel} (row {$rowNo}) "
                                    ."should go to {$required} or higher. Reassign to a higher-ranked teammate, "
                                    .'or accept the quality risk if the senior pool is saturated.',
                                'context' => [
                                    'row_no' => $rowNo,
                                    'phase_code' => $phaseCode,
                                    'assignee_id' => $assigneeId,
                                    'assignee_rank' => $actual,
                                    'required_rank' => $required,
                                    'assignee_level' => $assigneeLevel,
                                    'required_level' => $minLevel,
                                    'difficulty' => $difficulty,
                                ],
                            ];
                        }
                    }
                }

                $start = Carbon::parse($entry['planned_start'])->startOfDay();
                $end = Carbon::parse($entry['planned_end'])->startOfDay();

                if ($end->lessThan($start)) {
                    $violations[] = [
                        'code' => 'inverted_range',
                        'message' => "planned_end before planned_start (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'planned_start' => $entry['planned_start'], 'planned_end' => $entry['planned_end']],
                    ];

                    continue;
                }

                if ($start->lessThan($windowStart) || $end->greaterThan($effectiveEnd)) {
                    $violations[] = [
                        'code' => 'out_of_window',
                        'message' => "Range {$entry['planned_start']}..{$entry['planned_end']} is outside the project window {$windowStart->toDateString()}..{$effectiveEnd->toDateString()} (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'window_start' => $windowStart->toDateString(), 'window_end' => $effectiveEnd->toDateString()],
                    ];
                }

                if (! $calendar->isWorkingDay($start, $assigneeId)) {
                    $violations[] = [
                        'code' => 'non_working_start',
                        'message' => "planned_start {$entry['planned_start']} is not a working day for assignee (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'date' => $entry['planned_start']],
                    ];
                }
                if (! $calendar->isWorkingDay($end, $assigneeId)) {
                    $violations[] = [
                        'code' => 'non_working_end',
                        'message' => "planned_end {$entry['planned_end']} is not a working day for assignee (row {$rowNo}, phase {$phaseCode}).",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $phaseCode, 'date' => $entry['planned_end']],
                    ];
                }

                // Duration sanity.
                $hpd = max(self::HOURS_PER_DAY_FLOOR, $payload->hoursPerDayFor($assigneeId, self::DEFAULT_HOURS_PER_DAY));
                $expectedDays = max(1, (int) ceil(((float) $phaseDef['hours']) / $hpd));
                $actualDays = $calendar->workingDaysBetween($start, $end, $assigneeId);
                $minDays = max(1, (int) floor($expectedDays * (1 - self::DURATION_TOLERANCE)));
                $maxDays = max($minDays, (int) ceil($expectedDays * (1 + self::DURATION_TOLERANCE)));
                if ($actualDays < $minDays || $actualDays > $maxDays) {
                    $violations[] = [
                        'code' => 'duration_out_of_tolerance',
                        'message' => "Duration {$actualDays} working days is outside the tolerated range {$minDays}..{$maxDays} for {$phaseDef['hours']}h at {$hpd}h/day (row {$rowNo}, phase {$phaseCode}).",
                        'context' => [
                            'row_no' => $rowNo, 'phase_code' => $phaseCode,
                            'actual_days' => $actualDays, 'expected_days' => $expectedDays,
                            'hours_per_day' => $hpd, 'estimated_hours' => $phaseDef['hours'],
                        ],
                    ];
                }

                $intervals[$assigneeId][] = [
                    'start' => $start,
                    'end' => $end,
                    'row_no' => $rowNo,
                    'phase' => $phaseCode,
                ];

                // Accumulate hours for the per-assignee cap check below.
                $assignedHoursByAssignee[$assigneeId] =
                    ($assignedHoursByAssignee[$assigneeId] ?? 0.0) + (float) $phaseDef['hours'];
            }

            // 3. Phase order — within a task, sorted by phase_order, planned_start non-decreasing.
            $ordered = [];
            foreach ($task['phases'] as $p) {
                if (isset($payload->assignmentsByRowPhase[$rowNo][$p['code']])) {
                    $entry = $payload->assignmentsByRowPhase[$rowNo][$p['code']];
                    $ordered[] = [
                        'order' => $p['order'] ?? 0,
                        'code' => $p['code'],
                        'start' => Carbon::parse($entry['planned_start']),
                    ];
                }
            }
            usort($ordered, fn ($a, $b) => $a['order'] <=> $b['order']);
            for ($i = 1; $i < count($ordered); $i++) {
                if ($ordered[$i]['start']->lessThan($ordered[$i - 1]['start'])) {
                    $violations[] = [
                        'code' => 'phase_order_violation',
                        'message' => "Phase {$ordered[$i]['code']} starts before earlier phase {$ordered[$i - 1]['code']} in row {$rowNo}.",
                        'context' => ['row_no' => $rowNo, 'phase_code' => $ordered[$i]['code'], 'previous_phase' => $ordered[$i - 1]['code']],
                    ];
                }
            }
        }

        // 4. Per-assignee allocation cap. Compares Σ(phase hours assigned)
        //    against the stored allocated_hours from project_team_assignments,
        //    which equals workable_hours × ghost_role.months — the member's
        //    honest engagement-window capacity. Tolerated overshoot is
        //    ALLOCATION_TOLERANCE (10%) to absorb estimator/rank-weight noise.
        foreach ($assignedHoursByAssignee as $assigneeId => $assignedHours) {
            $cap = $allocatedHoursByAssignee[$assigneeId] ?? null;
            if ($cap === null || $cap <= 0.0) {
                continue;
            }
            $ceiling = $cap * (1.0 + self::ALLOCATION_TOLERANCE);
            if ($assignedHours > $ceiling) {
                $violations[] = [
                    'code' => 'over_allocation',
                    'message' => "Assignee {$assigneeId} has ".number_format($assignedHours, 1)
                        .'h of phase work scheduled but their allocated_hours capacity is '
                        .number_format($cap, 1).'h (tolerated up to '.number_format($ceiling, 1).'h). '
                        .'Move excess hours to another team member in the same role pool, or reduce hours assigned to this person.',
                    'context' => [
                        'assignee_id' => $assigneeId,
                        'assigned_hours' => $assignedHours,
                        'allocated_hours' => $cap,
                        'ceiling' => $ceiling,
                        'overshoot' => $assignedHours - $cap,
                    ],
                ];
            }
        }

        // 5. Engagement-window span. A member contracted for N months on this
        //    project should not have phases spread across (much) more calendar
        //    time than that. We measure the span from earliest planned_start to
        //    latest planned_end and compare against months × ENGAGEMENT_DAYS_PER_MONTH.
        //    This catches a 3-month QA whose phases span all 5 months of the
        //    project window, leaving them paid-but-not-working in the middle.
        foreach ($engagementMonthsByAssignee as $assigneeId => $months) {
            if ($months <= 0 || empty($intervals[$assigneeId])) {
                continue;
            }
            $list = $intervals[$assigneeId];
            $minStart = $list[0]['start'];
            $maxEnd = $list[0]['end'];
            foreach ($list as $iv) {
                if ($iv['start']->lessThan($minStart)) {
                    $minStart = $iv['start'];
                }
                if ($iv['end']->greaterThan($maxEnd)) {
                    $maxEnd = $iv['end'];
                }
            }
            $spanDays = $minStart->diffInDays($maxEnd) + 1;
            $maxSpan = (int) ceil($months * self::ENGAGEMENT_DAYS_PER_MONTH * (1.0 + self::ENGAGEMENT_WINDOW_TOLERANCE));
            if ($spanDays > $maxSpan) {
                $violations[] = [
                    'code' => 'engagement_window_exceeded',
                    'message' => "Assignee {$assigneeId} phases span {$spanDays} calendar days "
                        ."({$minStart->toDateString()}..{$maxEnd->toDateString()}) but their "
                        .'engagement is '.number_format($months, 1).' months ('
                        ."≈ {$maxSpan} days with tolerance). Cluster their phases into a tighter window "
                        .'or assign the outlier phase(s) to another team member.',
                    'context' => [
                        'assignee_id' => $assigneeId,
                        'engagement_months' => $months,
                        'span_days' => $spanDays,
                        'max_span_days' => $maxSpan,
                        'first_start' => $minStart->toDateString(),
                        'last_end' => $maxEnd->toDateString(),
                    ],
                ];
            }
        }

        // 6. monthly_allocation enforcement — two HARD rules.
        //    Rule A: never assign anything in a calendar month where the
        //            member's monthly_allocation is 0 (off the project).
        //    Rule B: per (assignee, calendar month), sum of phase hours
        //            apportioned by working-day overlap must be ≤ the
        //            month's budget (workable_hours × allocation_fraction).
        //
        //    Both fire only when monthlyAllocationByAssignee is non-empty —
        //    legacy assignments without per-month allocation skip these.
        if (! empty($monthlyAllocationByAssignee)) {
            // Defensive: reject corrupt allocation arrays before they can
            // compute impossible budgets (e.g. an entry of 1.5 would yield
            // a 240h "budget" on a 160h-workable member and silently approve
            // over-allocation). Each entry must lie in [0.0, 1.0].
            foreach ($monthlyAllocationByAssignee as $assigneeIdCheck => $allocCheck) {
                if (! is_array($allocCheck)) {
                    continue;
                }
                foreach ($allocCheck as $i => $fraction) {
                    if ($fraction < 0.0 || $fraction > 1.0) {
                        $violations[] = [
                            'code' => 'invalid_allocation_fraction',
                            'message' => "Member {$assigneeIdCheck} has invalid monthly_allocation[{$i}] = {$fraction}. "
                                .'Must be between 0.0 and 1.0. Fix the project_team_assignments row before re-running.',
                            'context' => [
                                'assignee_id' => $assigneeIdCheck,
                                'index' => $i,
                                'value' => $fraction,
                            ],
                        ];
                    }
                }
            }

            // Build (assignee, year_month_bucket) → apportioned hours, plus
            // a reverse index of the source assignments so we can name the
            // offending row/phase in error messages.
            $bucketHours = [];
            $bucketSources = [];

            foreach ($payload->assignmentsByRowPhase as $rowNo => $byPhase) {
                $task = $tasksByRow[$rowNo] ?? null;
                if ($task === null) {
                    continue; // unknown_row already flagged above
                }
                $phasesByCode = [];
                foreach ($task['phases'] as $p) {
                    $phasesByCode[$p['code']] = $p;
                }
                foreach ($byPhase as $phaseCode => $entry) {
                    $assigneeId = $entry['assignee_id'];
                    $alloc = $monthlyAllocationByAssignee[$assigneeId] ?? null;
                    if (! is_array($alloc) || empty($alloc)) {
                        continue;
                    }
                    $teamStart = isset($teamStartByAssignee[$assigneeId])
                        ? Carbon::parse($teamStartByAssignee[$assigneeId])->startOfDay()
                        : $windowStart->copy();
                    $hours = (float) ($phasesByCode[$phaseCode]['hours'] ?? 0);
                    $start = Carbon::parse($entry['planned_start'])->startOfDay();
                    $end = Carbon::parse($entry['planned_end'])->startOfDay();
                    if ($end->lessThan($start) || $hours <= 0) {
                        continue;
                    }

                    // Working-day count per calendar month touched by the range.
                    $perMonthDays = $this->workingDaysPerMonth($calendar, $start, $end, $assigneeId);
                    $totalDays = array_sum($perMonthDays);
                    if ($totalDays === 0) {
                        continue; // non_working_start/end already flagged
                    }

                    foreach ($perMonthDays as $monthKey => $daysInMonth) {
                        $monthIndex = $this->monthIndexFrom($teamStart, $monthKey);
                        $fraction = $alloc[$monthIndex] ?? 0.0;
                        $apportioned = $hours * ($daysInMonth / $totalDays);

                        // Rule A — zero-month assignment.
                        if ($fraction <= 0.0) {
                            $violations[] = [
                                'code' => 'zero_month_assignment',
                                'message' => "Member {$assigneeId} cannot take phase {$phaseCode} of row {$rowNo}: "
                                    ."their monthly_allocation for {$monthKey} is 0 (off the project that month). "
                                    .'Reassign to a teammate who has capacity in that month, or move the phase to a month where this member is available.',
                                'context' => [
                                    'row_no' => $rowNo,
                                    'phase_code' => $phaseCode,
                                    'assignee_id' => $assigneeId,
                                    'month' => $monthKey,
                                    'month_index' => $monthIndex,
                                ],
                            ];

                            continue; // don't double-count zero-month into the budget bucket
                        }

                        $bucketKey = $assigneeId.'|'.$monthKey;
                        $bucketHours[$bucketKey] = ($bucketHours[$bucketKey] ?? 0.0) + $apportioned;
                        $bucketSources[$bucketKey][] = [
                            'row_no' => $rowNo,
                            'phase_code' => $phaseCode,
                            'apportioned_hours' => $apportioned,
                        ];
                    }
                }
            }

            // Rule B — compare each bucket against its monthly budget.
            foreach ($bucketHours as $bucketKey => $assignedHours) {
                [$assigneeId, $monthKey] = explode('|', $bucketKey, 2);
                $alloc = $monthlyAllocationByAssignee[$assigneeId] ?? [];
                $teamStart = isset($teamStartByAssignee[$assigneeId])
                    ? Carbon::parse($teamStartByAssignee[$assigneeId])->startOfDay()
                    : $windowStart->copy();
                $monthIndex = $this->monthIndexFrom($teamStart, $monthKey);
                $fraction = $alloc[$monthIndex] ?? 0.0;
                $workable = $workableHoursByAssignee[$assigneeId] ?? 160.0;
                $budget = $workable * $fraction;

                if ($assignedHours > $budget + self::MONTHLY_BUDGET_TOLERANCE_HOURS) {
                    $violations[] = [
                        'code' => 'monthly_budget_overrun',
                        'message' => "Member {$assigneeId} over-allocated in {$monthKey}: "
                            .number_format($assignedHours, 1).'h assigned vs '
                            .number_format($budget, 1).'h budget (allocation='
                            .number_format($fraction, 2).' × workable_hours='
                            .number_format($workable, 0).'h). Move some hours to another month where this member has capacity, or reassign the overshoot to another teammate.',
                        'context' => [
                            'assignee_id' => $assigneeId,
                            'month' => $monthKey,
                            'assigned_hours' => $assignedHours,
                            'budget_hours' => $budget,
                            'allocation_fraction' => $fraction,
                            'workable_hours' => $workable,
                            'sources' => $bucketSources[$bucketKey] ?? [],
                        ],
                    ];
                }
            }
        }

        // 7. Double-booking.
        foreach ($intervals as $assigneeId => $list) {
            usort($list, fn ($a, $b) => $a['start']->timestamp <=> $b['start']->timestamp);
            for ($i = 1; $i < count($list); $i++) {
                $prev = $list[$i - 1];
                $cur = $list[$i];
                if ($cur['start']->lessThanOrEqualTo($prev['end'])) {
                    $violations[] = [
                        'code' => 'double_booking',
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

    /**
     * Count working days per calendar month for the range [start, end],
     * inclusive of both ends. Returns map of "YYYY-MM" => working-day count.
     * Phase spanning month boundary → entries for each month it touches.
     */
    private function workingDaysPerMonth(
        WorkingDayCalendar $calendar,
        Carbon $start,
        Carbon $end,
        string $assigneeId,
    ): array {
        $perMonth = [];
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            if ($calendar->isWorkingDay($cursor, $assigneeId)) {
                $key = $cursor->format('Y-m');
                $perMonth[$key] = ($perMonth[$key] ?? 0) + 1;
            }
            $cursor->addDay();
        }

        return $perMonth;
    }

    /**
     * Translate a "YYYY-MM" key into its index in a monthly_allocation
     * array anchored at $teamStart. Returns negative on out-of-range
     * (caller treats negative index → fraction = 0 = zero-month rule).
     */
    private function monthIndexFrom(Carbon $teamStart, string $monthKey): int
    {
        [$year, $month] = array_map('intval', explode('-', $monthKey));
        $startIndex = $teamStart->year * 12 + ($teamStart->month - 1);
        $targetIndex = $year * 12 + ($month - 1);

        return $targetIndex - $startIndex;
    }
}
