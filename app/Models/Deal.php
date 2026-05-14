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
        if ($this->status !== 'qualified' || $this->isDropped()) {
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
}
