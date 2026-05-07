<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealGhostRole extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'deal_ghost_roles';

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'role_type',
        'quantity',
        'months',
        'avg_monthly_salary',
        'min_monthly_salary',
        'max_monthly_salary',
    ];

    protected $casts = [
        'id' => 'string',
        'quantity' => 'integer',
        'months' => 'integer',
        'avg_monthly_salary' => 'float',
        'min_monthly_salary' => 'float',
        'max_monthly_salary' => 'float',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }
}
