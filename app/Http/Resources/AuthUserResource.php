<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->relationLoaded('tenant') ? $this->tenant : null;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'app_role' => $this->app_role,
            'system_role' => $this->system_role,
            'is_super_admin' => (bool) $this->is_super_admin,
            'tenant' => $tenant
                ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'currency' => $tenant->currency ?? 'MMK',
                    'tax_rate' => (float) ($tenant->tax_rate ?? 0.20),
                    'avg_delivery_lag_months' => (int) ($tenant->avg_delivery_lag_months ?? 1),
                    'avg_payment_days_late' => (int) ($tenant->avg_payment_days_late ?? 0),
                ]
                : null,
        ];
    }
}
