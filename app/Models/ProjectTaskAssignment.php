<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTaskAssignment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'project_task_assignments';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'row_no',
        'function_id',
        'function_name',
        'category',
        'offshore',
        'difficulty',
        'total_hours',
        'assignee_id',
        'assignment_source',
        'planned_start',
        'planned_end',
        'actual_start',
        'actual_end',
        'status',
    ];

    protected $casts = [
        'row_no'        => 'integer',
        'total_hours'   => 'float',
        'planned_start' => 'date',
        'planned_end'   => 'date',
        'actual_start'  => 'date',
        'actual_end'    => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }
}
