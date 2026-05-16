<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    /**
     * Laravel auto-invokes `boot{TraitName}` on each trait at boot time, so
     * this method runs in addition to any `booted()` defined on the model
     * itself. The previous name (`booted`) was silently overridden whenever a
     * consuming model declared its own `booted()` — which is exactly what
     * `Employee` does for the cost_per_hour computed-column fallback. That
     * override dropped the tenant global scope, allowing queries to see every
     * tenant's employees. Use the trait-boot convention so both hooks
     * coexist and tenant isolation is enforced unconditionally.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->has('tenant_id')) {
                $table = $builder->getModel()->getTable();
                $builder->where("{$table}.tenant_id", app('tenant_id'));
            }
        });

        static::creating(function ($model) {
            if (app()->has('tenant_id') && empty($model->tenant_id)) {
                $model->tenant_id = app('tenant_id');
            }
        });
    }
}
