<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantAppRole extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system'  => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(TenantAppRolePermission::class, 'role_id');
    }

    public function permissionKeys(): array
    {
        return $this->permissions()->pluck('permission_key')->all();
    }
}
