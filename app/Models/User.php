<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// User intentionally does NOT use BelongsToTenant — login queries are global by email.
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'system_role',
        'app_role',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'id'                => 'string',
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'deleted_at'        => 'datetime',
            'is_super_admin'    => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedTimeEntries()
    {
        return $this->hasMany(TimeEntry::class, 'approved_by');
    }
}
