<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per tenant bank account. Rendered at the bottom of the
 * Invoice XLSX export (Kanbawza / AYA blocks in the reference
 * template). N accounts per tenant; sort_order controls render order.
 */
class TenantBankAccount extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'label',
        'account_name',
        'account_no',
        'branch_name',
        'branch_address',
        'branch_no',
        'swift_code',
        'sort_order',
    ];

    protected $casts = [
        'id'         => 'string',
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
