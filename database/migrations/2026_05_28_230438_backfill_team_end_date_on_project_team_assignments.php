<?php

use App\Models\ProjectTeamAssignment;
use App\Support\EngagementWindow;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: only touches rows with NULL team_end_date.
        // Eager-load the project + contract + deal so effectiveEndDate() can
        // compute its fallback (start_date + deal.timeline_months) without
        // N+1 queries.
        ProjectTeamAssignment::with('project.contract.deal')
            ->whereNull('team_end_date')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $startDate = $row->team_start_date
                        ? Carbon::parse($row->team_start_date)->startOfDay()
                        : null;

                    $end = EngagementWindow::computeEndDate(
                        $startDate,
                        $row->monthly_allocation,
                        $row->project,
                    );

                    if ($end === null) {
                        Log::warning('pta.team_end_date.backfill_skipped', [
                            'assignment_id' => $row->id,
                            'project_id' => $row->project_id,
                            'reason' => 'no team_start_date+monthly_allocation, no project end_date, and no deal.timeline_months',
                        ]);

                        continue;
                    }

                    $row->update(['team_end_date' => $end->toDateString()]);
                }
            });
    }

    public function down(): void
    {
        // Reversal: clear all team_end_date values. The schema migration that
        // adds the column has its own down() that drops it; this just undoes
        // the data side.
        ProjectTeamAssignment::query()->update(['team_end_date' => null]);
    }
};
