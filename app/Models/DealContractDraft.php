<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Persisted AI-generated contract draft per deal.
 *
 * See migration 2026_05_15_000005_create_deal_contract_drafts_table.php
 * for the lifecycle (draft → sent_to_customer → signed; or superseded
 * when regenerated).
 */
class DealContractDraft extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent_to_customer';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_SIGNED,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'template_id',
        'template_version_at_generation',
        'status',
        'version',
        'wizard_inputs',
        'ai_outputs',
        'sections',
        'generated_pdf_path',
        'sent_at',
        'sent_to_email',
        'signed_at',
        'signed_pdf_path',
        'generated_by_user_id',
        'finalized_by_user_id',
        'signatory_name_override',
        'signatory_title_override',
        'customer_signatory_name',
        'customer_signatory_title',
    ];

    protected $casts = [
        'id' => 'string',
        'wizard_inputs' => 'array',
        'ai_outputs' => 'array',
        'sections' => 'array',
        'sent_at' => 'datetime',
        'signed_at' => 'datetime',
        'template_version_at_generation' => 'integer',
        'version' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function finalizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }
}
