<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library of contract templates the AI contract drafting wizard renders.
 *
 * v1 ships with 3 SES variants seeded as global rows (tenant_id = NULL):
 *   - cloud_backup       — modelled on the Yazaki Veritas Backup Exec deal
 *   - managed_hosting    — 24/7 cloud operations with SLA-based pricing
 *   - engineer_dispatch  — traditional SES (engineer for N hours/month)
 *
 * Each row's `sections` JSON drives the wizard. See ContractDraftService
 * for how the wizard questions feed the Claude prompt, and how AI-written
 * sections are merged with fixed/slot-filled ones.
 *
 * Per-tenant overrides come later — admin CRUD UI is deferred to v2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: NULL = global template available to every tenant.
            // Per-tenant overrides UI is deferred, but the schema supports
            // it so v2 doesn't require another migration.
            $table->uuid('tenant_id')->nullable();
            $table->string('name', 150);
            $table->string('slug', 64);
            $table->string('umbrella', 32)->default('SES');
            $table->integer('version')->default(1);
            $table->json('sections');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Slug is unique per tenant scope (NULL tenant = global namespace).
            // Postgres treats NULL values as distinct in unique indexes, which
            // is what we want — multiple tenants can each have their own
            // 'cloud_backup' override; the global one also has slug='cloud_backup'.
            $table->unique(['tenant_id', 'slug'], 'idx_ct_tenant_slug');
            $table->index(['umbrella', 'is_active'], 'idx_ct_umbrella_active');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE contract_templates ADD CONSTRAINT check_ct_umbrella "
                . "CHECK (umbrella IN ('SES'))"
            );
            DB::statement(
                'ALTER TABLE contract_templates ADD CONSTRAINT check_ct_version '
                . 'CHECK (version >= 1)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
