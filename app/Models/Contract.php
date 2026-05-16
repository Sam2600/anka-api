<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use App\Traits\BelongsToTenant;

class Contract extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

    // Auto-status transitions fire automatically without user action. Both
    // rules are encapsulated in maybeAutoTransition() below and called from
    // two places: TimeEntryController::approve (real-time on Active→Completed)
    // and the AutoTransitionContracts artisan command (daily for Signed→Active
    // plus a belt-and-suspenders re-check of completion). See
    // storage/contract_auto_status_decision.md for the why.
    public const STATUS_DRAFT = 'Draft';

    public const STATUS_SIGNED = 'Signed';

    public const STATUS_ACTIVE = 'Active';

    public const STATUS_COMPLETED = 'Completed';

    public const STATUS_CANCELLED = 'Cancelled';

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'contract_number',
        'client',
        'total_value',
        'revenue_recognized',
        'cash_collected',
        'status',
        'start_date',
        'end_date',
        'signed_at',
        'payment_terms_days',
        'po_number',
        'billing_contact_name',
        'billing_email',
        'currency',
        'tax_jurisdiction',
        'notes',
    ];

    protected $casts = [
        'id'                 => 'string',
        'total_value'        => 'float',
        'revenue_recognized' => 'float',
        'cash_collected'     => 'float',
        'start_date'         => 'date',
        'end_date'           => 'date',
        'signed_at'          => 'datetime',
        'payment_terms_days' => 'integer',
        'deleted_at'         => 'datetime',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function milestones()
    {
        return $this->hasMany(Milestone::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function project()
    {
        return $this->hasOne(Project::class);
    }

    /**
     * Apply the auto-status rules and persist the transition if one fires.
     * Pass in the project explicitly so callers can use a freshly-loaded one
     * (the time-entry approval path increments consumed_hours via raw DB
     * update, so $this->project would otherwise still hold the stale value).
     * Returns the new status string when a transition fired, otherwise null.
     *
     * Rule 1 (Signed → Active): today >= start_date.
     * Rule 2 (Active → Completed): project.consumed_hours >= project.budget_hours.
     *
     * @param  string  $trigger  Free-form label for logs — e.g. 'time_entry_approval'
     *                           or 'scheduled_command'. Helps audit which path
     *                           moved the contract.
     */
    public function maybeAutoTransition(?Project $project, string $trigger): ?string
    {
        // Activate (Signed → Active) — date-based.
        if ($this->status === self::STATUS_SIGNED
            && $this->start_date
            && $this->start_date->isPast()
        ) {
            $previous = $this->status;
            $this->update(['status' => self::STATUS_ACTIVE]);
            Log::info('contract.auto_activated', [
                'contract_id' => $this->id,
                'tenant_id' => $this->tenant_id,
                'previous_status' => $previous,
                'trigger' => $trigger,
            ]);

            return self::STATUS_ACTIVE;
        }

        // Complete (Active → Completed) — usage-based. Requires a project
        // with positive budget_hours; missing/zero budgets are unsafe to
        // auto-complete because consumed_hours >= 0 is trivially true.
        if ($this->status === self::STATUS_ACTIVE
            && $project
            && $project->budget_hours > 0
            && $project->consumed_hours >= $project->budget_hours
        ) {
            $previous = $this->status;
            $this->update(['status' => self::STATUS_COMPLETED]);
            Log::info('contract.auto_completed', [
                'contract_id' => $this->id,
                'tenant_id' => $this->tenant_id,
                'previous_status' => $previous,
                'budget_hours' => $project->budget_hours,
                'consumed_hours' => $project->consumed_hours,
                'trigger' => $trigger,
            ]);

            return self::STATUS_COMPLETED;
        }

        return null;
    }
}
