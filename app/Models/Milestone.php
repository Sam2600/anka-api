<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;

class Milestone extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'name',
        'due_date',
        'amount',
        'status',
        'completed_at',
        'acceptance_criteria',
        'accepted_at',
        'accepted_by_client',
        'sequence_number',
    ];

    protected $casts = [
        'id'              => 'string',
        'due_date'        => 'date',
        'amount'          => 'float',
        'completed_at'    => 'datetime',
        'accepted_at'     => 'datetime',
        'sequence_number' => 'integer',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
