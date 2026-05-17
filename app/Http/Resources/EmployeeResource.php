<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'role_name' => $this->role_name,
            'department_id' => $this->department_id,
            'department_name' => optional($this->department)->name,
            'job_role_id' => $this->job_role_id,
            'capacity_role' => $this->capacity_role,
            'capacity_role_id' => $this->capacity_role_id,
            'capacity_role_name' => optional($this->capacityRole)->name,
            // Rank — added in chg-009. Null when employee has no rank set
            // (legacy data, or new hires the org hasn't tagged yet). The AI
            // Team Builder falls back to role-title keyword matching when null.
            'rank_id' => $this->rank_id,
            'rank' => $this->whenLoaded('rank', fn () => $this->rank ? [
                'id' => $this->rank->id,
                'code' => $this->rank->code,
                'name' => $this->rank->name,
                'level' => (int) $this->rank->level,
            ] : null),
            // Spec ①.2 — salary split into Basic + Allowance. monthly_salary
            // remains as the derived total (basic + allowance) for legacy
            // readers; it's always recomputed server-side on save.
            'basic_salary' => $this->basic_salary,
            'allowance' => $this->allowance,
            'monthly_salary' => $this->monthly_salary,
            'workable_hours' => $this->workable_hours,
            'cost_per_hour' => $this->cost_per_hour,
            'status' => $this->status,
            'email' => optional($this->user)->email,
            'skills' => SkillResource::collection($this->whenLoaded('skills')),
        ];
    }
}
