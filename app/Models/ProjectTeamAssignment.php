<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTeamAssignment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'project_team_assignments';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'employee_id',
        'allocated_hours',
        'assignment_source',
    ];

    protected $casts = [
        'allocated_hours' => 'float',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
