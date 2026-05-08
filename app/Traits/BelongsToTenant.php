<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    protected static function booted()
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
