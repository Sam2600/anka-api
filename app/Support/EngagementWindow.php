<?php

namespace App\Support;

use App\Models\Project;
use Carbon\Carbon;

class EngagementWindow
{
    /**
     * Compute the end date of a project_team_assignments row.
     *
     * Preference order:
     *   1. team_start_date + count(monthly_allocation) months - 1 day  (precise; only available on the confirmTeamPlan writer)
     *   2. project.effectiveEndDate()  (fallback for legacy / hard-assignment writers)
     *   3. null  (caller decides — treated by the idle scope as "active forever")
     */
    public static function computeEndDate(
        ?Carbon $startDate,
        $monthlyAllocation,
        ?Project $project,
    ): ?Carbon {
        $alloc = is_array($monthlyAllocation) ? $monthlyAllocation : null;

        if ($startDate && $alloc !== null && count($alloc) > 0) {
            return $startDate->copy()->addMonths(count($alloc))->subDay();
        }

        return $project?->effectiveEndDate();
    }

    /**
     * Compute the [start, end] window of the target project that's about to
     * be staffed. Used by the idle-pool scope to filter employees by overlap.
     *
     * Start preference: explicit team_start_date (from sheet_team_structure)
     *                   → project.start_date → project.kickoff_date → today.
     * End preference:   project.effectiveEndDate() (uses end_date or
     *                   start_date + timeline_months) → start + 3 months.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function windowFor(Project $project, ?string $explicitStart = null): array
    {
        $start = $explicitStart
            ? Carbon::parse($explicitStart)->startOfDay()
            : ($project->start_date
                ? Carbon::parse($project->start_date)->startOfDay()
                : ($project->kickoff_date
                    ? Carbon::parse($project->kickoff_date)->startOfDay()
                    : Carbon::now()->startOfDay()));

        $end = $project->effectiveEndDate();

        if (! $end || $end->lessThanOrEqualTo($start)) {
            $months = (int) ($project->contract?->deal?->timeline_months ?? 3);
            $end = $start->copy()->addMonths(max(1, $months))->subDay();
        }

        return [$start, $end];
    }
}
