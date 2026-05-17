<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (employee, target_month). Holds the salary that applies
 * from `target_month` forward until a later row supersedes it. The
 * Employee record's basic_salary / allowance / cost_per_hour are
 * always the *current* row's values, kept as a denormalized cache so
 * legacy readers (estimation, profit, AI Team Builder, forecast) work
 * unchanged.
 *
 * See `Employee::salaryForDate()` for the most-recent-on-or-before
 * lookup used by date-aware code paths.
 */
class EmployeeSalaryHistory extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $table = 'employee_salary_history';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'target_month',
        'basic_salary',
        'allowance',
        'cost_per_hour',
        'workable_hours',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'id' => 'string',
        'target_month' => 'date',
        'basic_salary' => 'float',
        'allowance' => 'float',
        'cost_per_hour' => 'float',
        'workable_hours' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
