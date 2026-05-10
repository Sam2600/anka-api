<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanySettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'overhead_percentage'             => $this->overhead_percentage,
            'buffer_percentage'               => $this->buffer_percentage,
            'yearly_fixed_cost'               => $this->yearly_fixed_cost,
            'employer_tax_percentage'         => $this->employer_tax_percentage,
            'benefits_percentage'             => $this->benefits_percentage,
            'cost_to_bill_ratio'              => $this->cost_to_bill_ratio,
            'default_monthly_capacity_hours'  => $this->default_monthly_capacity_hours,
            'fallback_hourly_cost'            => $this->fallback_hourly_cost,
        ];
    }
}
