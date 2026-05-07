<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EstimationVersion extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'estimation_versions';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'version_number',
        'resources',
        'overheads',
        'target_margin',
        'notes',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'id' => 'string',
        'version_number' => 'integer',
        'resources' => 'array',
        'overheads' => 'array',
        'target_margin' => 'float',
        'created_at' => 'datetime',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }
}
