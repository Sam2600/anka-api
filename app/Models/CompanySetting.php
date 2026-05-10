<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;

// id is text DEFAULT 'singleton' — intentional exception to the UUID PK rule.
// Does NOT use HasUuids; PK is a plain string managed by the database default.
class CompanySetting extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'company_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'overhead_percentage',
        'buffer_percentage',
        'yearly_fixed_cost',
        'employer_tax_percentage',
        'benefits_percentage',
        'cost_to_bill_ratio',
        'default_monthly_capacity_hours',
        'fallback_hourly_cost',
    ];

    protected $casts = [
        'overhead_percentage'             => 'float',
        'buffer_percentage'               => 'float',
        'yearly_fixed_cost'               => 'float',
        'employer_tax_percentage'         => 'float',
        'benefits_percentage'             => 'float',
        'cost_to_bill_ratio'              => 'float',
        'default_monthly_capacity_hours'  => 'integer',
        'fallback_hourly_cost'            => 'float',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
