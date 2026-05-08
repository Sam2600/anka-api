<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSkill extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'employee_skills';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'skill_id',
        'proficiency',
    ];

    protected $casts = [
        'proficiency' => 'string',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
