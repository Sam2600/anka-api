<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    ];

    protected $casts = [
        'id'             => 'string',
        'budget_hours'   => 'float',
        'consumed_hours' => 'float',
        'start_date'     => 'date',
        'end_date'       => 'date',
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
}
