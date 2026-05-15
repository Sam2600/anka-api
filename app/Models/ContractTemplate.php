<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contract template library — each row is one variant the AI contract
 * drafting wizard can render.
 *
 * Unlike most domain models, ContractTemplate does NOT use the standard
 * BelongsToTenant trait because templates can be:
 *   - Global (tenant_id IS NULL) — seeded SES variants visible to all tenants
 *   - Tenant-owned (tenant_id = X) — per-tenant overrides
 *
 * The custom global scope below makes both visible to the current tenant.
 */
class ContractTemplate extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'umbrella',
        'version',
        'sections',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'sections' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
        'deleted_at' => 'datetime',
    ];

    /**
     * Scope queries to (global templates) ∪ (current tenant's templates).
     * Skipped when no tenant context (super-admin paths, console, tests
     * without a bound tenant).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant_or_global', function (Builder $builder) {
            if (! app()->has('tenant_id')) {
                return;
            }
            $tenantId = app('tenant_id');
            $builder->where(function (Builder $q) use ($tenantId) {
                $q->whereNull('contract_templates.tenant_id')
                  ->orWhere('contract_templates.tenant_id', $tenantId);
            });
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForUmbrella(Builder $query, string $umbrella): Builder
    {
        return $query->where('umbrella', $umbrella);
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}
