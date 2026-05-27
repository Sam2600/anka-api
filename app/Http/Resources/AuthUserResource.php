<?php

namespace App\Http\Resources;

use App\Http\Middleware\CheckPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->relationLoaded('tenant') ? $this->tenant : null;

        // Super admins are treated as having every permission on the frontend.
        // For org users the list comes from tenant_app_roles via CheckPermission.
        $permissions = $this->is_super_admin
            ? ['all']
            : CheckPermission::permissionsFor($this->resource);

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'app_role' => $this->app_role,
            'system_role' => $this->system_role,
            'is_super_admin' => (bool) $this->is_super_admin,
            'permissions' => $permissions,
            'tenant' => $tenant
                ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'currency' => $tenant->currency ?? 'MMK',
                    'tax_rate' => (float) ($tenant->tax_rate ?? 0.20),
                    'avg_delivery_lag_months' => (int) ($tenant->avg_delivery_lag_months ?? 1),
                    'avg_payment_days_late' => (int) ($tenant->avg_payment_days_late ?? 0),
                    // Surfaced in the auth payload so the frontend Company
                    // tab can show current values without a separate fetch.
                    'address' => $tenant->address,
                    'phone' => $tenant->phone,
                    'exchange_rates' => $tenant->exchangeRates()
                        ->where('to_currency', 'USD')
                        ->get(['from_currency', 'rate'])
                        ->keyBy('from_currency')
                        ->map(fn ($r) => (float) $r->rate),
                ]
                : null,
        ];
    }
}
