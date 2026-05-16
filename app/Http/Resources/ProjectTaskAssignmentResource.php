<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'project_id'    => $this->project_id,
            'row_no'        => $this->row_no,
            'function_id'   => $this->function_id,
            'function_name' => $this->function_name,
            'category'      => $this->category,
            'offshore'      => $this->offshore,
            'difficulty'    => $this->difficulty,
            'total_hours'   => $this->total_hours,
            'phases'        => ProjectTaskPhaseAssignmentResource::collection(
                $this->whenLoaded('phaseAssignments')
            ),
        ];
    }
}
