<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealContractDocument extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'uploaded_by',
        'original_filename',
        'mime_type',
        'extension',
        'size_bytes',
        'storage_path',
        'analysis_status',
        'analysis_result',
        'previous_analysis',
        'overall_score',
        'detected_payment_pattern',
        'analyzed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'analysis_result' => 'array',
        'previous_analysis' => 'array',
        'analyzed_at' => 'datetime',
        'size_bytes' => 'integer',
        'overall_score' => 'integer',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
