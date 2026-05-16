<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('date');
            $table->string('name', 150);
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->unique(['tenant_id', 'date']);
            $table->index(['tenant_id', 'date'], 'idx_holidays_tenant_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
