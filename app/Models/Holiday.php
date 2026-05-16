<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'holidays';

    protected $fillable = [
        'tenant_id',
        'date',
        'name',
        'is_recurring',
    ];

    protected $casts = [
        'date'         => 'date',
        'is_recurring' => 'bool',
    ];
}
