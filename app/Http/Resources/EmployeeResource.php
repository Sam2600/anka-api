<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'role'            => $this->role,
            'role_name'       => $this->role_name,
            'department_id'   => $this->department_id,
            'department_name' => optional($this->department)->name,
            'job_role_id'     => $this->job_role_id,
            'capacity_role'   => $this->capacity_role,
            'monthly_salary'  => $this->monthly_salary,
            'workable_hours'  => $this->workable_hours,
            'cost_per_hour'   => $this->cost_per_hour, // GENERATED column — read-only
            'status'          => $this->status,
            // Surface the linked user's email so the Edit form can pre-fill it.
            // Lazy-loads the relation if not eager-loaded — endpoints that already
            // call ->load('user') avoid the N+1.
            'email'           => optional($this->user)->email,
        ];
    }
}
