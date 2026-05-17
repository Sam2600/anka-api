<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeSalaryHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'target_month' => $this->target_month?->toDateString(),
            'basic_salary' => $this->basic_salary,
            'allowance' => $this->allowance,
            'monthly_salary' => $this->basic_salary + $this->allowance,
            'cost_per_hour' => $this->cost_per_hour,
            'workable_hours' => $this->workable_hours,
            'notes' => $this->notes,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
