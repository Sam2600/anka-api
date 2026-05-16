<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer contract document uploaded against a deal. The text content is
 * extracted on the fly via ContractAnalysisService::extractText() — this
 * model only owns the file metadata and analysis verdict columns.
 *
 * Lifecycle (analysis_status):
 *   pending → analyzing → approved | rejected | failed
 *
 * See migration 2026_05_12_000002_create_deal_contract_documents_table.php.
 */
class DealContractDocument extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ANALYZING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_FAILED,
    ];

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
        'analyzed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'size_bytes' => 'integer',
        'analysis_result' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
