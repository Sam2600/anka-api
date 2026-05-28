<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;

class DealOverhead extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'name',
        'cost',
        'months',
    ];

    protected $casts = [
        'id'     => 'string',
        'cost'   => 'float',
        'months' => 'integer',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }
}
