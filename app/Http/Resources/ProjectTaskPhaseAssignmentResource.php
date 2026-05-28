<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskPhaseAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_assignment_id' => $this->task_assignment_id,
            'phase_code' => $this->phase_code,
            'phase_name' => $this->phase_name,
            'phase_order' => $this->phase_order,
            'estimated_hours' => $this->estimated_hours,
            'start_day_hours' => $this->start_day_hours !== null ? (float) $this->start_day_hours : null,
            'assignee_id' => $this->assignee_id,
            'assignee_name' => optional($this->assignee)->name,
            'assignee_rank_id' => optional($this->assignee)->rank_id,
            'assignee_rank_code' => optional(optional($this->assignee)->rank)->code,
            'assignee_rank_name' => optional(optional($this->assignee)->rank)->name,
            'assignment_source' => $this->assignment_source,
            'planned_start' => optional($this->planned_start)->toDateString(),
            'planned_end' => optional($this->planned_end)->toDateString(),
            'planned_dates_edited_at' => optional($this->planned_dates_edited_at)->toIso8601String(),
            'actual_start' => optional($this->actual_start)->toDateString(),
            'actual_end' => optional($this->actual_end)->toDateString(),
            'status' => $this->status,
        ];
    }
}
