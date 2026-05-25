<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Department extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'manager',
        'manager_id',
        'headcount',
        'is_delivery_eligible',
    ];

    protected $casts = [
        'id'        => 'string',
        'manager_id' => 'string',
        'headcount' => 'integer',
        'is_delivery_eligible' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function managerEmployee()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
