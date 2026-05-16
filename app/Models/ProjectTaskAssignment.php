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
    ];

    protected $casts = [
        'row_no'      => 'integer',
        'total_hours' => 'float',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function phaseAssignments()
    {
        return $this->hasMany(ProjectTaskPhaseAssignment::class, 'task_assignment_id')->orderBy('phase_order');
    }
}
