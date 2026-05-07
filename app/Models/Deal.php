<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Deal extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

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
        'won_at',
        'lost_at',
        'win_reason',
        'loss_reason',
    ];

    protected $casts = [
        'id'                     => 'string',
        'estimated_value'        => 'float',
        'win_probability'        => 'integer',
        'client_budget'          => 'float',
        'timeline_months'        => 'integer',
        'workload_hours'         => 'float',
        'target_margin'          => 'float',
        'base_labor_cost'        => 'float',
        'overhead_cost'          => 'float',
        'buffer_cost'            => 'float',
        'total_estimated_cost'   => 'float',
        'estimated_gross_profit' => 'float',
        'expected_close_date'    => 'date',
        'won_at'                 => 'datetime',
        'lost_at'                => 'datetime',
        'deleted_at'             => 'datetime',
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
}
