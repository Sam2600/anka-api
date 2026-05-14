<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhaseProgressLog extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'phase_progress_logs';

    protected $fillable = [
        'tenant_id',
        'phase_assignment_id',
        'employee_id',
        'log_date',
        'progress_hours',
        'used_hours',
        'note',
        'locked_at',
    ];

    protected $casts = [
        'log_date'       => 'date',
        'progress_hours' => 'float',
        'used_hours'     => 'float',
        'locked_at'      => 'datetime',
    ];

    public function phaseAssignment()
    {
        return $this->belongsTo(ProjectTaskPhaseAssignment::class, 'phase_assignment_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
