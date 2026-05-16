<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use App\Traits\BelongsToTenant;
use App\Models\ProjectTeamAssignment;

class Project extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

    // Auto-status rules. Same trigger pattern as Contract::maybeAutoTransition:
    // real-time on time-entry approval + nightly cron belt-and-suspenders.
    // See storage/contract_auto_status_decision.md for the wider rationale.
    public const STATUS_NOT_STARTED = 'Not Started';

    public const STATUS_ON_TRACK = 'On Track';

    public const STATUS_AT_RISK = 'At Risk';

    public const STATUS_OVER_BUDGET = 'Over Budget';

    public const STATUS_COMPLETED = 'Completed';

    /** Burn-rate threshold for flipping On Track → At Risk. */
    public const AT_RISK_THRESHOLD = 0.80;

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'project_number',
        'name',
        'client',
        'budget_hours',
        'consumed_hours',
        'status',
        'start_date',
        'end_date',
        'kickoff_date',
        'project_manager_id',
    ];

    protected $casts = [
        'id'             => 'string',
        'budget_hours'   => 'float',
        'consumed_hours' => 'float',
        'start_date'     => 'date',
        'end_date'       => 'date',
        'kickoff_date'   => 'date',
        'deleted_at'     => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function time_entries()
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function teamAssignments()
    {
        return $this->hasMany(ProjectTeamAssignment::class);
    }

    public function projectManager()
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    /**
     * Compute and persist the project's status based on consumed_hours,
     * budget_hours, and the linked contract's status. Returns the new status
     * if a transition fired, otherwise null.
     *
     * Order of evaluation matters — callers from the time-entry approval path
     * MUST run Contract::maybeAutoTransition FIRST so the contract's status
     * is fresh when this method reads it.
     *
     * Rules:
     *   - contract Completed/Cancelled → Completed (mirror)
     *   - consumed > budget AND contract still active → Over Budget
     *   - 80% ≤ consumed/budget < 100% → At Risk
     *   - 0 < consumed < 80% of budget → On Track
     *   - consumed = 0 → Not Started
     *
     * Manual transitions still work — this method only fires when the
     * computed status differs from the current one, so a PM who manually set
     * the status sees their value persist until time-entry data warrants a
     * change.
     */
    public function maybeAutoTransition(?Contract $contract, string $trigger): ?string
    {
        $budget = (float) $this->budget_hours;
        $consumed = (float) $this->consumed_hours;
        $contractStatus = $contract?->status;
        $newStatus = $this->computeAutoStatus($budget, $consumed, $contractStatus);

        if ($newStatus === null || $newStatus === $this->status) {
            return null;
        }

        $previous = $this->status;
        $this->update(['status' => $newStatus]);

        Log::info('project.auto_transition', [
            'project_id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'previous_status' => $previous,
            'new_status' => $newStatus,
            'budget_hours' => $budget,
            'consumed_hours' => $consumed,
            'contract_status' => $contractStatus,
            'trigger' => $trigger,
        ]);

        return $newStatus;
    }

    /**
     * Pure function returning the computed status. Separated from the
     * persisting wrapper so tests can exercise the decision tree without
     * touching the DB.
     */
    public function computeAutoStatus(float $budget, float $consumed, ?string $contractStatus): ?string
    {
        // Contract Completed/Cancelled wins — project follows.
        if (in_array($contractStatus, ['Completed', 'Cancelled'], true)) {
            return self::STATUS_COMPLETED;
        }

        // Budget hours not set yet — no signal to act on.
        if ($budget <= 0) {
            return null;
        }

        if ($consumed <= 0) {
            return self::STATUS_NOT_STARTED;
        }

        $ratio = $consumed / $budget;

        if ($ratio > 1.0) {
            return self::STATUS_OVER_BUDGET;
        }

        if ($ratio >= self::AT_RISK_THRESHOLD) {
            return self::STATUS_AT_RISK;
        }

        return self::STATUS_ON_TRACK;
    }
}
