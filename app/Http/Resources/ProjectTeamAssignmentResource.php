<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTeamAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'employee_id' => $this->employee_id,
            'employee_name' => optional($this->employee)->name,
            'allocated_hours' => $this->allocated_hours,
            'assignment_source' => $this->assignment_source,
            'cost_per_hour' => optional($this->employee)->cost_per_hour,
            'monthly_salary' => optional($this->employee)->monthly_salary,
        ];
    }
}
