<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Contract extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'contract_number',
        'client',
        'total_value',
        'revenue_recognized',
        'cash_collected',
        'status',
        'start_date',
        'end_date',
        'signed_at',
        'payment_terms_days',
        'po_number',
        'billing_contact_name',
        'billing_email',
        'currency',
        'tax_jurisdiction',
        'notes',
    ];

    protected $casts = [
        'id'                 => 'string',
        'total_value'        => 'float',
        'revenue_recognized' => 'float',
        'cash_collected'     => 'float',
        'start_date'         => 'date',
        'end_date'           => 'date',
        'signed_at'          => 'datetime',
        'payment_terms_days' => 'integer',
        'deleted_at'         => 'datetime',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function milestones()
    {
        return $this->hasMany(Milestone::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function project()
    {
        return $this->hasOne(Project::class);
    }
}
