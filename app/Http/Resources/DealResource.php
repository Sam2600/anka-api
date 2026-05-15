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
            // chg-009 Phase B — orthogonal lifecycle flag (replaces 'lost' rank).
            'lifecycle_status' => $this->lifecycle_status,
            'dropped_at_stage' => $this->dropped_at_stage,
            'dropped_at' => $this->dropped_at?->toIso8601String(),
            // chg-009 Phase B — computed rank + lock state for the frontend.
            'rank' => $this->rank,
            'wizard_step' => $this->wizard_step,
            'expected_close_date' => $this->expected_close_date?->format('Y-m-d'),
            'lead_source' => $this->lead_source,
            'client_budget' => $this->client_budget,
            'timeline_months' => $this->timeline_months,
            'workload_hours' => $this->workload_hours,
            'workload_description' => $this->workload_description,
            // chg-011 Phase A prep — OT/overage expectation captured at nego.
            // Used by ⑦ Profit Calculate (absorbed OT reduces profit) and the
            // AI contract drafting prompt (renders the OT clause).
            'ot_policy_model' => $this->ot_policy_model,
            'ot_rate_per_hour' => $this->ot_rate_per_hour,
            'ot_included_hours_per_month' => $this->ot_included_hours_per_month,
            'ot_notes' => $this->ot_notes,
            // Customer requirements collected progressively during nego.
            // ④ Estimation reads them when pricing; ⑤ contract drafting renders
            // them as clauses; missing values surface as placeholder markers
            // in the AI output for the operator to resolve in wizard step 2.
            'customer_support_obligations' => $this->customer_support_obligations,
            'out_of_scope_policy' => $this->out_of_scope_policy,
            'working_hours' => $this->working_hours,
            'testing_range' => $this->testing_range,
            'target_margin' => $this->target_margin,
            'base_labor_cost' => $this->base_labor_cost,
            'overhead_cost' => $this->overhead_cost,
            'buffer_cost' => $this->buffer_cost,
            'total_estimated_cost' => $this->total_estimated_cost,
            'estimated_gross_profit' => $this->estimated_gross_profit,
            // chg-011 Phase B — Estimation handoff fields (read-only here).
            'final_monthly_fee' => $this->final_monthly_fee,
            'final_installation_fee' => $this->final_installation_fee,
            'final_contract_months' => $this->final_contract_months,
            'final_ot_policy' => $this->final_ot_policy,
            'final_support_hours_per_month' => $this->final_support_hours_per_month,
            'final_team_summary' => $this->final_team_summary,
            'final_currency' => $this->final_currency,
            'final_confirmed_at' => $this->final_confirmed_at?->toIso8601String(),
            'suggested_template_variant' => $this->suggested_template_variant,
            'win_reason' => $this->win_reason,
            'loss_reason' => $this->loss_reason,
            'has_sent_contract_draft' => (bool) ($this->has_sent_contract_draft ?? false),
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
