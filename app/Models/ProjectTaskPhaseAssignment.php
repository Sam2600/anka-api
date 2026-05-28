<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTaskPhaseAssignment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'project_task_phase_assignments';

    protected $fillable = [
        'tenant_id',
        'task_assignment_id',
        'phase_code',
        'phase_name',
        'phase_order',
        'estimated_hours',
        'start_day_hours',
        'assignee_id',
        'assignment_source',
        'planned_start',
        'planned_end',
        'planned_dates_edited_at',
        'actual_start',
        'actual_end',
        'status',
    ];

    protected $casts = [
        'phase_order' => 'integer',
        'estimated_hours' => 'float',
        'start_day_hours' => 'float',
        'planned_start' => 'date',
        'planned_end' => 'date',
        'planned_dates_edited_at' => 'datetime',
        'actual_start' => 'date',
        'actual_end' => 'date',
    ];

    public function taskAssignment()
    {
        return $this->belongsTo(ProjectTaskAssignment::class, 'task_assignment_id');
    }

    public function assignee()
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }

    public function progressLogs()
    {
        return $this->hasMany(PhaseProgressLog::class, 'phase_assignment_id')->orderBy('log_date');
    }
}
