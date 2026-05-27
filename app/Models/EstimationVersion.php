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
        'context_notes',
        'created_by',
        'created_at',
        'xlsx_path',
        'sheet_function_list',
        'sheet_manhour_detail',
        'sheet_milestone',
        'sheet_team_structure',
    ];

    protected $casts = [
        'id' => 'string',
        'version_number' => 'integer',
        'resources' => 'array',
        'overheads' => 'array',
        'target_margin' => 'float',
        'created_at' => 'datetime',
        'sheet_function_list' => 'array',
        'sheet_manhour_detail' => 'array',
        'sheet_milestone' => 'array',
        'sheet_team_structure' => 'array',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }
}
