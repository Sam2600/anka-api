<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use App\Traits\BelongsToTenant;
use App\Models\ProjectTeamAssignment;

class Project extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'project_number',
        'name',
        'client',
        'budget_hours',
        'consumed_hours',
        'status',
        'start_date',
        'end_date',
        'kickoff_date',
        'project_manager_id',
    ];

    protected $casts = [
        'id'             => 'string',
        'budget_hours'   => 'float',
        'consumed_hours' => 'float',
        'start_date'     => 'date',
        'end_date'       => 'date',
        'kickoff_date'   => 'date',
        'deleted_at'     => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function time_entries()
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function teamAssignments()
    {
        return $this->hasMany(ProjectTeamAssignment::class);
    }

    public function projectManager()
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    /**
     * Returns the project's effective end date.
     *
     * Falls back to start_date + deal.timeline_months when end_date is null,
     * so projects scoped only by "X months from start" still have a window
     * for AI task planning and Gantt-style UI rendering.
     *
     * Requires `contract.deal` eager-loaded to compute the fallback.
     */
    public function effectiveEndDate(): ?Carbon
    {
        if ($this->end_date) {
            return Carbon::parse($this->end_date)->startOfDay();
        }
        if (! $this->start_date) {
            return null;
        }
        $months = $this->contract?->deal?->timeline_months;
        if (! $months) {
            return null;
        }

        return Carbon::parse($this->start_date)->startOfDay()->addMonths((int) $months);
    }

    public function endDateIsEstimated(): bool
    {
        return ! $this->end_date && $this->effectiveEndDate() !== null;
    }
}
