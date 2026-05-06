<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Intentionally does NOT use BelongsToTenant — tenant scoping is done manually
// in AiUsageController so the super-admin endpoint can query across all tenants.
class AiUsageLog extends Model
{
    use HasFactory, HasUuids;

    const UPDATED_AT = null; // Audit logs are immutable

    protected $fillable = [
        'tenant_id',
        'user_id',
        'feature',
        'model',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
    ];

    protected $casts = [
        'id'                  => 'string',
        'input_tokens'        => 'integer',
        'output_tokens'       => 'integer',
        'estimated_cost_usd'  => 'float',
        'created_at'          => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
