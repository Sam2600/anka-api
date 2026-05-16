<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;
use Illuminate\Support\Facades\DB;

// cost_per_hour is a PostgreSQL GENERATED column — never add it to $fillable.
class Employee extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'department_id',
        'job_role_id',
        'name',
        'role',
        'role_name',
        'capacity_role',
        'capacity_role_id',
        'rank_id',
        'monthly_salary',
        'workable_hours',
        'status',
    ];

    protected $casts = [
        'id'             => 'string',
        'monthly_salary' => 'float',
        'workable_hours' => 'integer',
        'cost_per_hour'  => 'float',
    ];

    protected static function booted()
    {
        static::saving(function ($model) {
            if (DB::getDriverName() !== 'pgsql') {
                if ($model->workable_hours > 0) {
                    $model->cost_per_hour = $model->monthly_salary / $model->workable_hours;
                } else {
                    $model->cost_per_hour = 0;
                }
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function jobRole()
    {
        return $this->belongsTo(Role::class, 'job_role_id');
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function timeEntries()
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function hardAssignments()
    {
        return $this->hasMany(DealHardAssignment::class);
    }

    public function capacityRole()
    {
        return $this->belongsTo(CapacityRole::class, 'capacity_role_id');
    }

    public function rank()
    {
        return $this->belongsTo(Rank::class, 'rank_id');
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'employee_skills', 'employee_id', 'skill_id')
            ->withPivot('proficiency')
            ->withTimestamps();
    }
}
