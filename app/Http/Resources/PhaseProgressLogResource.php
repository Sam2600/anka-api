<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhaseProgressLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'phase_assignment_id' => $this->phase_assignment_id,
            'employee_id'         => $this->employee_id,
            'employee_name'       => optional($this->employee)->name,
            'log_date'            => optional($this->log_date)->toDateString(),
            'progress_hours'      => $this->progress_hours,
            'used_hours'          => $this->used_hours,
            'note'                => $this->note,
            'locked_at'           => optional($this->locked_at)->toIso8601String(),
            'is_locked'           => $this->locked_at !== null,
            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
