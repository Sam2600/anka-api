<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // team_size: cheap rollup so the projects list page can show team count
        // without a per-row query. Driven by the existing teamAssignments
        // relation (populated by win_deal() or /projects/{id}/team endpoints).
        $teamSize = method_exists($this->resource, 'teamAssignments')
            ? $this->resource->teamAssignments()->count()
            : 0;

        return [
            'id'                   => $this->id,
            'contract_id'          => $this->contract_id,
            'project_number'       => $this->project_number,
            'name'                 => $this->name,
            'client'               => $this->client,
            'budget_hours'         => $this->budget_hours,
            'consumed_hours'       => $this->consumed_hours,
            'status'               => $this->status,
            'start_date'           => $this->start_date?->toDateString(),
            'end_date'             => $this->end_date?->toDateString(),
            'kickoff_date'         => $this->kickoff_date?->toDateString(),
            'project_manager_id'   => $this->project_manager_id,
            'project_manager_name' => optional($this->projectManager)->name,
            'team_size'            => $teamSize,
        ];
    }
}
