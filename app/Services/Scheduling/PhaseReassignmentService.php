<?php

namespace App\Services\Scheduling;

use App\Models\PhaseProgressLog;
use App\Models\Project;
use App\Models\ProjectTaskPhaseAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PhaseReassignmentService
{
    public function __construct(
        private WorkingDayCalendar $calendar,
    ) {}

    /**
     * Find the target assignee's existing phases that overlap with the given
     * date range. Cross-project, tenant-scoped (via BelongsToTenant global scope).
     *
     * @return array<int, array{phase_assignment_id: string, phase_name: string, phase_code: string, function_name: string, project_name: string, planned_start: string, planned_end: string, estimated_hours: float}>
     */
    public function detectConflicts(
        string $assigneeId,
        Carbon $plannedStart,
        Carbon $plannedEnd,
        string $excludePhaseId,
    ): array {
        $conflicts = ProjectTaskPhaseAssignment::with(['taskAssignment.project'])
            ->where('assignee_id', $assigneeId)
            ->where('id', '!=', $excludePhaseId)
            ->whereNotNull('planned_start')
            ->whereNotNull('planned_end')
            ->where('planned_start', '<=', $plannedEnd->toDateString())
            ->where('planned_end', '>=', $plannedStart->toDateString())
            ->orderBy('planned_start')
            ->get();

        return $conflicts->map(fn (ProjectTaskPhaseAssignment $p) => [
            'phase_assignment_id' => $p->id,
            'phase_name' => $p->phase_name,
            'phase_code' => $p->phase_code,
            'function_name' => optional($p->taskAssignment)->function_name ?? '',
            'project_name' => optional(optional($p->taskAssignment)->project)->name ?? '',
            'planned_start' => $p->planned_start->toDateString(),
            'planned_end' => $p->planned_end->toDateString(),
            'estimated_hours' => (float) $p->estimated_hours,
        ])->all();
    }

    /**
     * Calculate new planned_start/planned_end that avoid conflicts by
     * starting after the latest conflicting phase ends.
     */
    public function calculateReadjustedDates(
        string $assigneeId,
        Carbon $originalStart,
        Carbon $originalEnd,
        float $estimatedHours,
        string $excludePhaseId,
    ): array {
        $latestEnd = ProjectTaskPhaseAssignment::where('assignee_id', $assigneeId)
            ->where('id', '!=', $excludePhaseId)
            ->whereNotNull('planned_end')
            ->where('planned_end', '>=', $originalStart->toDateString())
            ->max('planned_end');

        if (! $latestEnd) {
            return [
                'planned_start' => $originalStart->toDateString(),
                'planned_end' => $originalEnd->toDateString(),
            ];
        }

        $durationDays = $this->calendar->workingDaysBetween($originalStart, $originalEnd, $assigneeId);
        $durationDays = max(1, $durationDays);

        $newStart = $this->calendar->nextWorkingDay(
            Carbon::parse($latestEnd)->addDay(),
            $assigneeId,
        );
        $newEnd = $durationDays > 1
            ? $this->calendar->addWorkingDays($newStart->copy(), $durationDays - 1, $assigneeId)
            : $newStart->copy();

        return [
            'planned_start' => $newStart->toDateString(),
            'planned_end' => $newEnd->toDateString(),
        ];
    }

    /**
     * Walk the assignee's later phases and shift each forward if it overlaps
     * the previous one. Returns the list of shifted phases + warnings.
     *
     * @param  bool  $dryRun  When true, don't persist — just return the preview.
     * @return array{shifted: array, warnings: string[]}
     */
    public function cascadeShift(
        string $assigneeId,
        Carbon $afterDate,
        string $excludePhaseId,
        bool $dryRun = false,
    ): array {
        $phases = ProjectTaskPhaseAssignment::with(['taskAssignment.project'])
            ->where('assignee_id', $assigneeId)
            ->where('id', '!=', $excludePhaseId)
            ->whereNotNull('planned_start')
            ->whereNotNull('planned_end')
            ->where('planned_start', '>=', $afterDate->toDateString())
            ->orderBy('planned_start')
            ->limit(100)
            ->lockForUpdate()
            ->get();

        $shifted = [];
        $warnings = [];
        $prevEnd = $afterDate->copy();

        foreach ($phases as $phase) {
            $currentStart = $phase->planned_start;
            $currentEnd = $phase->planned_end;

            if ($currentStart->lessThanOrEqualTo($prevEnd)) {
                $durationDays = $this->calendar->workingDaysBetween($currentStart, $currentEnd, $assigneeId);
                $durationDays = max(1, $durationDays);

                $newStart = $this->calendar->nextWorkingDay($prevEnd->copy()->addDay(), $assigneeId);
                $newEnd = $durationDays > 1
                    ? $this->calendar->addWorkingDays($newStart->copy(), $durationDays - 1, $assigneeId)
                    : $newStart->copy();

                $project = optional($phase->taskAssignment)->project;
                $projectEnd = $project instanceof Project ? $project->effectiveEndDate() : null;

                if ($projectEnd && $newEnd->greaterThan($projectEnd)) {
                    $warnings[] = "Phase \"{$phase->phase_name}\" ({$phase->taskAssignment?->function_name}) would be pushed past project end date ({$projectEnd->toDateString()})";
                }

                $shifted[] = [
                    'phase_assignment_id' => $phase->id,
                    'phase_name' => $phase->phase_name,
                    'function_name' => optional($phase->taskAssignment)->function_name ?? '',
                    'original_start' => $currentStart->toDateString(),
                    'original_end' => $currentEnd->toDateString(),
                    'new_start' => $newStart->toDateString(),
                    'new_end' => $newEnd->toDateString(),
                ];

                if (! $dryRun) {
                    // start_day_hours sized the *original* first day. If the
                    // shifted window still has the same working-day length,
                    // the partial-day carve still makes sense. If the length
                    // changed, null it out so VarianceCalculator falls back
                    // to even-distribution rather than misreading the plan.
                    $oldDuration = $this->calendar->workingDaysBetween($currentStart, $currentEnd, $assigneeId);
                    $newDuration = $this->calendar->workingDaysBetween($newStart, $newEnd, $assigneeId);
                    $updates = [
                        'planned_start' => $newStart->toDateString(),
                        'planned_end' => $newEnd->toDateString(),
                    ];
                    if ($oldDuration !== $newDuration) {
                        $updates['start_day_hours'] = null;
                    }
                    $phase->update($updates);
                }

                $prevEnd = $newEnd->copy();
            } else {
                $prevEnd = $currentEnd->copy();
            }
        }

        return ['shifted' => $shifted, 'warnings' => $warnings];
    }

    /**
     * Execute a reassignment: change assignee, optionally readjust dates + cascade.
     */
    public function executeReassignment(
        ProjectTaskPhaseAssignment $phase,
        string $newAssigneeId,
        ?string $newPlannedStart = null,
        ?string $newPlannedEnd = null,
        bool $cascade = false,
    ): array {
        return DB::transaction(function () use ($phase, $newAssigneeId, $newPlannedStart, $newPlannedEnd, $cascade) {
            $phase->lockForUpdate();

            $updates = [
                'assignee_id' => $newAssigneeId,
                'assignment_source' => 'manual',
            ];
            if ($newPlannedStart !== null) {
                $updates['planned_start'] = $newPlannedStart;
            }
            if ($newPlannedEnd !== null) {
                $updates['planned_end'] = $newPlannedEnd;
            }

            $phase->update($updates);
            $phase->load('assignee.rank');

            $cascadeResult = ['shifted' => [], 'warnings' => []];
            if ($cascade && $phase->planned_end) {
                $cascadeResult = $this->cascadeShift(
                    $newAssigneeId,
                    Carbon::parse($phase->planned_end),
                    $phase->id,
                    dryRun: false,
                );
            }

            return [
                'phase' => $phase,
                'shifted_phases' => $cascadeResult['shifted'],
                'warnings' => $cascadeResult['warnings'],
            ];
        });
    }

    /**
     * Swap assignees between two phases. Dates stay the same.
     * Returns post-swap conflict warnings for both employees.
     */
    public function executeSwap(
        ProjectTaskPhaseAssignment $phaseA,
        ProjectTaskPhaseAssignment $phaseB,
    ): array {
        return DB::transaction(function () use ($phaseA, $phaseB) {
            $phaseA->lockForUpdate();
            $phaseB->lockForUpdate();

            $assigneeA = $phaseA->assignee_id;
            $assigneeB = $phaseB->assignee_id;

            $phaseA->update(['assignee_id' => $assigneeB, 'assignment_source' => 'manual']);
            $phaseB->update(['assignee_id' => $assigneeA, 'assignment_source' => 'manual']);

            $phaseA->load('assignee.rank');
            $phaseB->load('assignee.rank');

            $warnings = [];

            if ($phaseA->planned_start && $phaseA->planned_end) {
                $conflictsForNewA = $this->detectConflicts(
                    $assigneeA,
                    $phaseB->planned_start,
                    $phaseB->planned_end,
                    $phaseB->id,
                );
                if (count($conflictsForNewA) > 0) {
                    $names = implode(', ', array_column($conflictsForNewA, 'phase_name'));
                    $warnings[] = "Employee previously on Phase A now has overlapping phases: {$names}";
                }
            }

            if ($phaseB->planned_start && $phaseB->planned_end) {
                $conflictsForNewB = $this->detectConflicts(
                    $assigneeB,
                    $phaseA->planned_start,
                    $phaseA->planned_end,
                    $phaseA->id,
                );
                if (count($conflictsForNewB) > 0) {
                    $names = implode(', ', array_column($conflictsForNewB, 'phase_name'));
                    $warnings[] = "Employee previously on Phase B now has overlapping phases: {$names}";
                }
            }

            return [
                'phase_a' => $phaseA,
                'phase_b' => $phaseB,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Remaining hours on a phase after subtracting logged progress.
     */
    public function remainingHours(ProjectTaskPhaseAssignment $phase): float
    {
        $logged = PhaseProgressLog::where('phase_assignment_id', $phase->id)->sum('progress_hours');

        return round(max(0.0, (float) $phase->estimated_hours - (float) $logged), 2);
    }
}
