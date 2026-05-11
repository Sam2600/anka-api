<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->smallInteger('avg_delivery_lag_months')->default(1)->after('tax_rate');
            $table->smallInteger('avg_payment_days_late')->default(0)->after('avg_delivery_lag_months');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['avg_delivery_lag_months', 'avg_payment_days_late']);
        });
    }
};
