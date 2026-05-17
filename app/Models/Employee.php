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
        'basic_salary',
        'allowance',
        // monthly_salary is kept fillable for backfill / seeder convenience,
        // but it's always overwritten in the saving() hook below so
        // monthly_salary === basic_salary + allowance is an invariant. Don't
        // rely on direct writes — set basic_salary + allowance instead.
        'monthly_salary',
        'workable_hours',
        'status',
    ];

    protected $casts = [
        'id'             => 'string',
        'basic_salary'   => 'float',
        'allowance'      => 'float',
        'monthly_salary' => 'float',
        'workable_hours' => 'integer',
        'cost_per_hour'  => 'float',
    ];

    /**
     * Maintain two invariants on every save:
     *   1. monthly_salary = basic_salary + allowance — soft-cutover total
     *      kept in sync with the new structural fields so legacy readers
     *      (estimation, profit calc, AI team builder, forecast) keep
     *      working unchanged. Mirrors the spec's ①.2 split.
     *   2. cost_per_hour = monthly_salary / workable_hours — already a
     *      Postgres GENERATED column; SQLite (tests) gets the same value
     *      computed in PHP since it doesn't support generated columns.
     */
    protected static function booted()
    {
        static::saving(function ($model) {
            $model->monthly_salary = ((float) $model->basic_salary) + ((float) $model->allowance);

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
