<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAppRolePermission extends Model
{
    use HasUuids;

    protected $fillable = [
        'role_id',
        'permission_key',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(TenantAppRole::class, 'role_id');
    }
}
