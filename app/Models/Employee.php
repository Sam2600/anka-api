<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

// cost_per_hour is a PostgreSQL GENERATED column — never add it to $fillable.
class Employee extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

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
        'id' => 'string',
        'basic_salary' => 'float',
        'allowance' => 'float',
        'monthly_salary' => 'float',
        'workable_hours' => 'integer',
        'cost_per_hour' => 'float',
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

        // Spec ②.1.B — every employee owns a salary timeline. The first
        // row is auto-created on hire from whatever basic_salary +
        // allowance the create payload supplied; effective from the first
        // of the current month. After this, all salary changes go through
        // the salary-history endpoints (create new rows for future months;
        // past rows are read-only).
        static::created(function (Employee $model) {
            $exists = EmployeeSalaryHistory::where('employee_id', $model->id)->exists();
            if ($exists) {
                return;
            }
            $hours = max(1, $model->workable_hours ?: 160);
            EmployeeSalaryHistory::create([
                'tenant_id' => $model->tenant_id,
                'employee_id' => $model->id,
                'target_month' => now()->startOfMonth()->toDateString(),
                'basic_salary' => $model->basic_salary ?? 0,
                'allowance' => $model->allowance ?? 0,
                'cost_per_hour' => round((((float) ($model->basic_salary ?? 0)) + ((float) ($model->allowance ?? 0))) / $hours, 4),
                'workable_hours' => $hours,
            ]);
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

    public function teamAssignments()
    {
        return $this->hasMany(ProjectTeamAssignment::class);
    }

    /**
     * "Available to staff a new project." Active full-timers with no current
     * project_team_assignments row at all. Used by both the AI Team Builder
     * pool (planTeamPreview) and the manual employee picker on the Team
     * Preview dialog so both sides draw from the same conceptual pool.
     */
    public function scopeIdleAndFullTime($query)
    {
        return $query
            ->where('status', 'Active')
            ->where('workable_hours', '>=', 160)
            ->whereDoesntHave('teamAssignments');
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

    /**
     * Salary timeline (spec ②.1.B). Most-recent first by target_month.
     * Each row holds the basic + allowance + cost_per_hour snapshot
     * that applies from `target_month` until the next row.
     */
    public function salaryHistory()
    {
        return $this->hasMany(EmployeeSalaryHistory::class)
            ->orderByDesc('target_month');
    }

    /**
     * Returns the salary-history row that applies on the given date —
     * the most recent row whose `target_month` is on or before $date.
     * Falls back to null if no rows exist (defensive — the migration
     * backfills one row per existing employee so this should be rare).
     */
    public function salaryForDate(\DateTimeInterface $date): ?EmployeeSalaryHistory
    {
        return $this->salaryHistory()
            ->where('target_month', '<=', $date->format('Y-m-d'))
            ->orderByDesc('target_month')
            ->first();
    }
}
