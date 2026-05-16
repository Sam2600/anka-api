<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'plan',
        'logo_path',
        'signatory_name',
        'signatory_title',
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

    /**
     * Public URL for the tenant's logo, or null when no logo is set.
     * Used by the frontend to preview the logo on the Company settings page.
     * The PDF renderer reads the filesystem path directly via logoAbsolutePath().
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }

    /**
     * Absolute filesystem path to the tenant's logo, or null when no logo
     * is set or the file is missing on disk. The PDF service uses this to
     * embed the image as a base64 data URI (Dompdf can't reach storage/).
     */
    public function logoAbsolutePath(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }
        $abs = Storage::disk('public')->path($this->logo_path);
        return is_file($abs) ? $abs : null;
    }

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
