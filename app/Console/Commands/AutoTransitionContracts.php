<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily auto-status pass for contracts AND projects. Runs the same
 * Contract::maybeAutoTransition + Project::maybeAutoTransition rules used by
 * the real-time time-entry-approval hook, but on a schedule so date-based
 * transitions (Signed → Active when start_date arrives) actually fire —
 * there's no event for "the calendar advanced past start_date."
 *
 * Also re-evaluates the completion / burn-rate rules as a belt-and-suspenders
 * against any time-entry approval that somehow missed the hot path (queue
 * failure, partial write). Idempotent: rows already in the target status
 * are no-ops.
 *
 * Order of work per contract: Contract first, then its linked Project, so
 * the project's Completed rule can read the contract's possibly-just-flipped
 * status. See storage/contract_auto_status_decision.md for the wider why.
 */
class AutoTransitionContracts extends Command
{
    protected $signature = 'contracts:auto-transition';

    protected $description = 'Auto-activate signed contracts whose start_date has arrived, auto-complete active contracts whose project burned through its budget_hours, and re-evaluate project statuses from time-entry data.';

    public function handle(): int
    {
        $contractsActivated = 0;
        $contractsCompleted = 0;
        $projectsUpdated = 0;

        // Bypass the tenant scope — this runs in CLI with no bound tenant.
        // The model methods log the tenant_id per transition for audit.
        Contract::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [
                Contract::STATUS_SIGNED,
                Contract::STATUS_ACTIVE,
            ])
            ->with(['project'])
            ->chunkById(500, function ($contracts) use (&$contractsActivated, &$contractsCompleted, &$projectsUpdated) {
                foreach ($contracts as $contract) {
                    $contractResult = $contract->maybeAutoTransition($contract->project, 'scheduled_command');
                    if ($contractResult === Contract::STATUS_ACTIVE) {
                        $contractsActivated++;
                    } elseif ($contractResult === Contract::STATUS_COMPLETED) {
                        $contractsCompleted++;
                    }

                    // Refresh project before re-evaluating in case the contract
                    // status just changed — its Completed rule mirrors contract.
                    $project = $contract->project?->fresh();
                    if ($project) {
                        $projectResult = $project->maybeAutoTransition($contract->fresh(), 'scheduled_command');
                        if ($projectResult !== null) {
                            $projectsUpdated++;
                        }
                    }
                }
            });

        // Sweep projects whose contract was already Completed/Cancelled in a
        // prior pass — those don't show up in the loop above (filtered to
        // Signed/Active contracts), but their projects might still need to
        // mirror to Completed if they got out of sync.
        Project::query()
            ->withoutGlobalScopes()
            ->whereNotIn('status', [Project::STATUS_COMPLETED])
            ->with(['contract'])
            ->chunkById(500, function ($projects) use (&$projectsUpdated) {
                foreach ($projects as $project) {
                    $result = $project->maybeAutoTransition($project->contract, 'scheduled_command');
                    if ($result !== null) {
                        $projectsUpdated++;
                    }
                }
            });

        $this->info("Auto-transition pass complete: contracts activated {$contractsActivated}, contracts completed {$contractsCompleted}, projects updated {$projectsUpdated}.");

        if ($contractsActivated > 0 || $contractsCompleted > 0 || $projectsUpdated > 0) {
            Log::info('contract.auto_transition_batch', [
                'contracts_activated' => $contractsActivated,
                'contracts_completed' => $contractsCompleted,
                'projects_updated' => $projectsUpdated,
            ]);
        }

        return self::SUCCESS;
    }
}
