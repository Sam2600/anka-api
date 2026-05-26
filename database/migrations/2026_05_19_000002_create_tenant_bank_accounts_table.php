<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant bank accounts rendered at the bottom of the Invoice
 * XLSX export (the "Kanbawza Bank Limited" / "AYA BANK LIMITED"
 * blocks in the reference template). N accounts per tenant — the
 * Company tab UI lets ops add as many as they need.
 *
 * sort_order controls the render sequence on the invoice; lower
 * values appear first. All address/branch fields are nullable so
 * partial entries (e.g. just account_name + account_no) still work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('label', 255);
            $table->string('account_name', 255)->nullable();
            $table->string('account_no', 100)->nullable();
            $table->string('branch_name', 255)->nullable();
            $table->string('branch_address', 500)->nullable();
            $table->string('branch_no', 50)->nullable();
            $table->string('swift_code', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'sort_order'], 'idx_tenant_bank_accounts_tenant_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_bank_accounts');
    }
};
