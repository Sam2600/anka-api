<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'plan',
        'currency',
        'tax_rate',
        'avg_delivery_lag_months',
        'avg_payment_days_late',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'is_active' => 'boolean',
        'tax_rate' => 'float',
        'avg_delivery_lag_months' => 'integer',
        'avg_payment_days_late' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function globalOverheads()
    {
        return $this->hasMany(GlobalOverhead::class);
    }

    public function companySetting()
    {
        return $this->hasOne(CompanySetting::class);
    }

    public function exchangeRates()
    {
        return $this->hasMany(ExchangeRate::class);
    }
}
