<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Skill extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
    ];

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_skills', 'skill_id', 'employee_id')
            ->withPivot('proficiency')
            ->withTimestamps();
    }
}
