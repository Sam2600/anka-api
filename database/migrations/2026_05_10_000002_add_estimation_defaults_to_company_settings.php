<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add tenant-configurable estimation defaults to company_settings.
 *
 *  - cost_to_bill_ratio              (default 0.40) — fraction of role.rate
 *    that represents the agency's true labor cost. Replaces the hardcoded
 *    `role.rate * 0.4` fallback in EstimationSimulator.
 *
 *  - default_monthly_capacity_hours  (default 160) — assumed monthly working
 *    hours per employee when an explicit value is unavailable. Replaces the
 *    `* 160` magic number used in ghost-role-to-resources conversion.
 *
 *  - fallback_hourly_cost            (default 50) — a tenant-currency floor
 *    used by the estimator when neither an active employee nor a role
 *    record can supply a cost rate. Replaces the bare `?? 50` literal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('cost_to_bill_ratio', 5, 4)->default(0.40)->after('benefits_percentage');
            $table->unsignedSmallInteger('default_monthly_capacity_hours')->default(160)->after('cost_to_bill_ratio');
            $table->decimal('fallback_hourly_cost', 10, 2)->default(50)->after('default_monthly_capacity_hours');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['cost_to_bill_ratio', 'default_monthly_capacity_hours', 'fallback_hourly_cost']);
        });
    }
};
