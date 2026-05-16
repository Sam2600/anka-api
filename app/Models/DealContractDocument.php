<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer contract documents uploaded against a deal during the
 * negotiation (A-rank) stage. Each row tracks the uploaded file plus
 * the Claude analysis verdict.
 *
 * Lifecycle:
 *   pending → analyzing → approved | rejected | failed
 *
 * When `analysis_status = 'approved'` the deal auto-transitions to `won`
 * via the win_deal() stored procedure.
 *
 * See migrations:
 *   - 2026_05_12_000002_create_deal_contract_documents_table.php
 *   - 2026_05_13_000001_alter_deal_contract_documents_for_rich_verdicts.php
 */
class DealContractDocument extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_FAILED    = 'failed';

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
        'previous_analysis',
        'overall_score',
        'detected_payment_pattern',
        'analyzed_at',
    ];

    protected $casts = [
        'id'                => 'string',
        'analysis_result'   => 'array',
        'previous_analysis' => 'array',
        'overall_score'     => 'integer',
        'size_bytes'        => 'integer',
        'analyzed_at'       => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
