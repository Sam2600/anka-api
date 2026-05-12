<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3)->default('USD');
            $table->decimal('rate', 14, 6);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'from_currency', 'to_currency'], 'idx_exchange_rates_tenant_currency');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT check_exchange_rates_rate_positive CHECK (rate > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
