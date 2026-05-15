<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted AI-generated contract drafts per deal.
 *
 * Lifecycle:
 *   draft               — wizard step 2 (user editing AI output)
 *   sent_to_customer    — emailed via ContractEmailService; awaiting signature
 *   signed              — counter-signed PDF uploaded; deal → rank S
 *   superseded          — newer draft generated; older row preserved for audit
 *
 * `version` increments per regeneration so we can replay a deal's drafting
 * history. The active draft is the highest version with status != superseded.
 *
 * `wizard_inputs` holds the Path C answers verbatim — feeds the Claude
 * prompt and lets the salesperson re-open the wizard at the same answers.
 * `ai_outputs` holds Claude's raw response keyed by section.key.
 * `sections` is the final merged form (fixed text + AI outputs + slot fills),
 *   editable by the user in step 2 — the renderer uses this, not ai_outputs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_contract_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('deal_id');
            $table->uuid('template_id');
            $table->integer('template_version_at_generation');

            $table->string('status', 24)->default('draft');
            $table->integer('version')->default(1);

            $table->json('wizard_inputs');
            $table->json('ai_outputs');
            $table->json('sections');

            $table->string('generated_pdf_path', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to_email', 255)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_pdf_path', 500)->nullable();

            $table->uuid('generated_by_user_id')->nullable();
            $table->uuid('finalized_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('deal_id')->references('id')->on('deals')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('contract_templates')->onDelete('restrict');
            $table->foreign('generated_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('finalized_by_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['tenant_id', 'deal_id'], 'idx_dcd_tenant_deal_for_drafts');
            $table->index(['tenant_id', 'status'], 'idx_dcd_tenant_status_for_drafts');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE deal_contract_drafts ADD CONSTRAINT check_dcd_draft_status "
                . "CHECK (status IN ('draft','sent_to_customer','signed','superseded'))"
            );
            DB::statement(
                'ALTER TABLE deal_contract_drafts ADD CONSTRAINT check_dcd_draft_version '
                . 'CHECK (version >= 1)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_contract_drafts');
    }
};
