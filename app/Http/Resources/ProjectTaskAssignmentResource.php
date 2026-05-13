<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'project_id'        => $this->project_id,
            'row_no'            => $this->row_no,
            'function_id'       => $this->function_id,
            'function_name'     => $this->function_name,
            'category'          => $this->category,
            'offshore'          => $this->offshore,
            'difficulty'        => $this->difficulty,
            'total_hours'       => $this->total_hours,
            'assignee_id'        => $this->assignee_id,
            'assignee_name'      => optional($this->assignee)->name,
            'assignee_rank_id'   => optional($this->assignee)->rank_id,
            'assignee_rank_code' => optional(optional($this->assignee)->rank)->code,
            'assignee_rank_name' => optional(optional($this->assignee)->rank)->name,
            'assignment_source' => $this->assignment_source,
            'planned_start'     => optional($this->planned_start)->toDateString(),
            'planned_end'       => optional($this->planned_end)->toDateString(),
            'actual_start'      => optional($this->actual_start)->toDateString(),
            'actual_end'        => optional($this->actual_end)->toDateString(),
            'status'            => $this->status,
        ];
    }
}
