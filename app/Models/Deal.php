<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    public const STATUS_TO_RANK = [
        'lead' => 'C',
        'qualified' => 'B',
        'negotiation' => 'A',
        'won' => 'S',
    ];

    public const RANK_PROBABILITY = [
        'C' => 30,
        'B' => 50,
        'A' => 80,
        'S' => 100,
    ];

    public const ALLOWED_STATUS_TRANSITIONS = [
        'lead' => ['qualified'],
        'qualified' => ['negotiation'],
        'negotiation' => ['won'],
        'won' => [],
    ];

    public const LOCKED_STATUSES = ['negotiation', 'won'];

    public const FIELDS_LOCKED_IN_A_OR_S = [
        'workload_description',
        'timeline_months',
        'client_budget',
        'ot_policy_model',
        'ot_rate_per_hour',
        'ot_included_hours_per_month',
        'ot_notes',
        'customer_support_obligations',
        'out_of_scope_policy',
        'working_hours',
        'testing_range',
        'final_monthly_fee',
        'final_installation_fee',
        'final_contract_months',
        'final_ot_policy',
        'final_support_hours_per_month',
        'final_team_summary',
        'final_currency',
        'final_confirmed_at',
        'suggested_template_variant',
    ];

    public const OT_POLICY_MODELS = [
        // Customer is billed for every overtime hour worked (typical SES).
        'customer_pays_per_hour',
        // First N hours/month are absorbed by Provider; beyond that customer pays.
        // Matches the Yazaki contract pattern ("12 hrs/mo support, then per-hour").
        'capped_then_customer_pays',
        // Provider eats the OT cost. ⑦ Profit Calculate must subtract it.
        'absorbed_by_provider',
        // Contract forbids OT entirely (rare; usually a flag for fixed-scope work).
        'no_overtime_allowed',
    ];

    public const REQUIRED_ESTIMATION_FIELDS = [
        'final_monthly_fee',
        'final_contract_months',
        'final_team_summary',
        'final_currency',
        'final_confirmed_at',
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'client',
        'contact_name',
        'contact_email',
        'contact_phone',
        'customer_address',
        'estimated_value',
        'win_probability',
        'status',
        'lifecycle_status',
        'dropped_at_stage',
        'dropped_at',
        'expected_close_date',
        'lead_source',
        'client_budget',
        'timeline_months',
        'workload_hours',
        'workload_description',
        'ot_policy_model',
        'ot_rate_per_hour',
        'ot_included_hours_per_month',
        'ot_notes',
        'customer_support_obligations',
        'out_of_scope_policy',
        'working_hours',
        'testing_range',
        'target_margin',
        'base_labor_cost',
        'overhead_cost',
        'buffer_cost',
        'total_estimated_cost',
        'estimated_gross_profit',
        'final_monthly_fee',
        'final_installation_fee',
        'final_contract_months',
        'final_ot_policy',
        'final_support_hours_per_month',
        'final_team_summary',
        'final_currency',
        'final_confirmed_at',
        'suggested_template_variant',
        'won_at',
        'lost_at',
        'win_reason',
        'loss_reason',
        'wizard_step',
    ];

    protected $casts = [
        'id' => 'string',
        'estimated_value' => 'float',
        'win_probability' => 'integer',
        'client_budget' => 'float',
        'timeline_months' => 'integer',
        'workload_hours' => 'float',
        'target_margin' => 'float',
        'base_labor_cost' => 'float',
        'overhead_cost' => 'float',
        'buffer_cost' => 'float',
        'total_estimated_cost' => 'float',
        'estimated_gross_profit' => 'float',
        'ot_rate_per_hour' => 'float',
        'ot_included_hours_per_month' => 'integer',
        'final_monthly_fee' => 'float',
        'final_installation_fee' => 'float',
        'final_contract_months' => 'integer',
        'final_support_hours_per_month' => 'integer',
        'final_confirmed_at' => 'datetime',
        'expected_close_date' => 'date',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
        'dropped_at' => 'datetime',
        'deleted_at' => 'datetime',
        'wizard_step' => 'string',
    ];

    public function contract()
    {
        return $this->hasOne(Contract::class);
    }

    /**
     * The tenant that owns this deal. Used by the contract drafting flow
     * to resolve per-tenant branding (provider name + logo) even from
     * the queue worker, where the tenant scope isn't bound by middleware.
     * The FK is set by the BelongsToTenant trait on create.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ghost_roles()
    {
        return $this->hasMany(DealGhostRole::class);
    }

    public function hard_assignments()
    {
        return $this->hasMany(DealHardAssignment::class);
    }

    public function estimation_resources()
    {
        return $this->hasMany(EstimationResource::class);
    }

    public function deal_overheads()
    {
        return $this->hasMany(DealOverhead::class);
    }

    public function getRankAttribute(): string
    {
        if ($this->isDropped()) {
            return 'Dropped';
        }

        return self::STATUS_TO_RANK[$this->status] ?? 'C';
    }

    public function isDropped(): bool
    {
        return $this->lifecycle_status === 'dropped';
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true)
            && ! $this->isDropped();
    }

    public function lockedFields(): array
    {
        return $this->isLocked() ? self::FIELDS_LOCKED_IN_A_OR_S : [];
    }

    public function canBeDropped(): bool
    {
        return ! $this->isDropped()
            && $this->status !== 'won'
            && $this->status !== 'lost';
    }

    public function contract_drafts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\DealContractDraft::class, 'deal_id');
    }

    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->isDropped()) {
            return false;
        }

        $allowed = self::ALLOWED_STATUS_TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    public function isContractEligible(): bool
    {
        if ($this->status !== 'negotiation' || $this->isDropped()) {
            return false;
        }

        foreach (self::REQUIRED_ESTIMATION_FIELDS as $field) {
            if (blank($this->{$field})) {
                return false;
            }
        }

        return true;
    }

    public function missingEstimationFields(): array
    {
        return array_values(array_filter(
            self::REQUIRED_ESTIMATION_FIELDS,
            fn (string $field) => blank($this->{$field})
        ));
    }

    /**
     * Whether the deal has any estimation rows or overhead lines persisted.
     * The Estimation menu writes both via DealController::update (auto-save +
     * AI Generate) and EstimationVersionController::store (Save Version);
     * either path counts as "user has started estimating."
     */
    public function hasStartedEstimation(): bool
    {
        return $this->estimation_resources()->exists()
            || $this->deal_overheads()->exists();
    }

    /**
     * C → B auto-advance when the user starts estimating. Mirrors the inline
     * B → A trigger in DealController::update — the lead's rank flips up the
     * moment a row lands on estimation_resources or deal_overheads, with the
     * win_probability bumped to the B-rank value. Forward-only and no-op for
     * dropped deals, deals already past C, or deals with no estimation rows.
     */
    public function maybePromoteToQualified(): void
    {
        if (
            $this->status === 'lead'
            && ! $this->isDropped()
            && $this->canTransitionTo('qualified')
            && $this->hasStartedEstimation()
        ) {
            $this->update([
                'status' => 'qualified',
                'win_probability' => self::RANK_PROBABILITY['B'],
            ]);
        }
    }

    /**
     * Spec ⑥.B — translate Estimation's per-feature resource rows into
     * deal_hard_assignments (the structured team list `win_deal()` copies
     * into ProjectTeamAssignment). Without this step, Estimation's team
     * suggestion lives only as a string in the contract; the post-win
     * Project team would be empty for any deal not seeded directly.
     *
     * Aggregates estimation_resources by employee_id (rows without an
     * employee_id are skipped — those are role-only sketches, not
     * concrete assignments). Idempotent: wipes existing
     * hard_assignments and rewrites from the current resources. Safe to
     * call after every estimation save while the deal is still active
     * (lead → negotiation). Skips once the deal is won so the Project's
     * team isn't retroactively reshuffled.
     */
    public function syncHardAssignmentsFromEstimation(): void
    {
        if ($this->status === 'won' || $this->isDropped()) {
            return;
        }

        $totals = $this->estimation_resources()
            ->whereNotNull('employee_id')
            ->get(['employee_id', 'hours'])
            ->groupBy('employee_id')
            ->map(fn ($group) => (float) $group->sum('hours'));

        // No estimation rows with concrete employee_id → don't touch
        // hard_assignments. Seeded deals + deals where the user only
        // sketched roles (no specific people) keep whatever hard
        // assignments were set manually.
        if ($totals->isEmpty()) {
            return;
        }

        $this->hard_assignments()->delete();

        foreach ($totals as $employeeId => $hours) {
            if ($hours <= 0) {
                continue;
            }
            DealHardAssignment::create([
                'tenant_id'       => $this->tenant_id,
                'deal_id'         => $this->id,
                'employee_id'     => $employeeId,
                'allocated_hours' => $hours,
            ]);
        }
    }

    /**
     * True when the agency absorbs the OT cost. ⑦ Profit Calculate
     * subtracts the actual overtime hours × the engineer's cost rate
     * from the deal's profit when this returns true.
     *
     * Note: 'capped_then_customer_pays' counts as partially absorbed
     * (first N hours per month are absorbed). Profit Calculate handles
     * the calculation; this helper just reports whether ANY portion is
     * absorbed so callers can decide whether to do the math.
     */
    public function isOvertimeAbsorbed(): bool
    {
        return in_array($this->ot_policy_model, [
            'absorbed_by_provider',
            'capped_then_customer_pays',
        ], true);
    }

    /**
     * Mark this deal Dropped. Captures the rank at the moment of drop
     * (analytics: "dropped at C" vs "dropped at A after burning estimation
     * effort"). Throws DomainException for callers that violate the
     * preconditions; HTTP layer translates to 422.
     */
    public function drop(string $reason): void
    {
        if (! $this->canBeDropped()) {
            throw new \DomainException(
                $this->isDropped()
                    ? 'Deal is already dropped.'
                    : "Deal at rank {$this->rank} cannot be dropped."
            );
        }

        $this->update([
            'lifecycle_status' => 'dropped',
            'dropped_at_stage' => $this->status,
            'dropped_at' => now(),
            'loss_reason' => $reason,
            'win_probability' => 0,
        ]);
    }

    /**
     * Returns the field-level errors a request would hit if it tried to
     * write any of the given keys while the deal is locked. Empty array
     * when the deal isn't locked or no request keys collide. Used by
     * DealController::update() to produce 422 errors.
     *
     * @param  array<string,mixed>  $requestKeys  array_keys() of the incoming payload
     * @return array<string,array<string>>
     */
    public function lockViolations(array $requestKeys): array
    {
        if (! $this->isLocked()) {
            return [];
        }

        $blocked = array_intersect($requestKeys, $this->lockedFields());
        $message = "This field is locked because the deal is at rank {$this->rank}. "
            . 'To change scope, drop this deal and start a new one.';

        return collect($blocked)
            ->mapWithKeys(fn (string $key) => [$key => [$message]])
            ->all();
    }
}
