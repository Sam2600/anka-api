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
            // Daily effort overage: when used_hours > progress_hours the
            // employee spent more clock-time than they earned in delivery.
            // Finance multiplies this by cost_per_hour to estimate overtime
            // cost. Clamped to ≥0 so days where they made up time aren't
            // double-counted as negative late_hours.
            'late_hours'          => round(max(0.0, (float) $this->used_hours - (float) $this->progress_hours), 2),
            'note'                => $this->note,
            'locked_at'           => optional($this->locked_at)->toIso8601String(),
            'is_locked'           => $this->locked_at !== null,
            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
