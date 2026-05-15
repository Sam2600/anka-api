<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'estimated_value' => $this->estimated_value,
            'win_probability' => $this->win_probability,
            'status' => $this->status,
            'wizard_step' => $this->wizard_step,
            'expected_close_date' => $this->expected_close_date?->format('Y-m-d'),
            'lead_source' => $this->lead_source,
            'client_budget' => $this->client_budget,
            'timeline_months' => $this->timeline_months,
            'workload_hours' => $this->workload_hours,
            'workload_description' => $this->workload_description,
            'target_margin' => $this->target_margin,
            'base_labor_cost' => $this->base_labor_cost,
            'overhead_cost' => $this->overhead_cost,
            'buffer_cost' => $this->buffer_cost,
            'total_estimated_cost' => $this->total_estimated_cost,
            'estimated_gross_profit' => $this->estimated_gross_profit,
            'win_reason' => $this->win_reason,
            'loss_reason' => $this->loss_reason,
            'ghost_roles' => $this->whenLoaded('ghost_roles', fn () => $this->ghost_roles->map(fn ($gr) => [
                'id' => $gr->id,
                'role_type' => $gr->role_type,
                'quantity' => $gr->quantity,
                'months' => $gr->months,
                'avg_monthly_salary' => $gr->avg_monthly_salary,
                'min_monthly_salary' => $gr->min_monthly_salary ?? $gr->avg_monthly_salary,
                'max_monthly_salary' => $gr->max_monthly_salary ?? $gr->avg_monthly_salary,
            ])
            ),
            'hard_assignments' => $this->whenLoaded('hard_assignments', fn () => $this->hard_assignments->map(fn ($ha) => [
                'employee_id' => $ha->employee_id,
                'allocated_hours' => $ha->allocated_hours,
            ])
            ),
            'estimation_resources' => $this->whenLoaded('estimation_resources', fn () => $this->estimation_resources->map(fn ($er) => [
                'id' => $er->id,
                'feature_name' => $er->feature_name,
                'role_id' => $er->role_id,
                'employee_id' => $er->employee_id,
                'hours' => $er->hours,
            ])
            ),
            'deal_overheads' => $this->whenLoaded('deal_overheads', fn () => $this->deal_overheads->map(fn ($oh) => [
                'id' => $oh->id,
                'name' => $oh->name,
                'cost' => $oh->cost,
            ])
            ),
        ];
    }
}
