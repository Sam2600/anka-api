<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Year-scoped target profit declaration — process ①.3 ("Initial Budget").
 * One row per (tenant, fiscal_year). The Forecast page (process ⑧) reads
 * the row matching the year of the displayed months and compares the
 * 6-month profit projection against it.
 *
 * Replaces the legacy `company_settings.annual_initial_budget` singleton.
 * That column is still readable during the soft cutover phase but the UI
 * writes here exclusively from 2026-05-17 onwards.
 */
class InitialBudget extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'fiscal_year',
        'amount',
        'created_by_user_id',
    ];

    protected $casts = [
        'id' => 'string',
        'fiscal_year' => 'integer',
        'amount' => 'float',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
