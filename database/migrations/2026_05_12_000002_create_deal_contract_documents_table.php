<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer contract documents uploaded while the deal sits in the
 * `negotiation` (A-rank) stage. Each row tracks the uploaded file plus the
 * Claude analysis verdict. When `analysis_status = 'approved'` the deal
 * auto-transitions to `won` via win_deal().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_contract_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('deal_id');
            $table->uuid('uploaded_by')->nullable();

            $table->string('original_filename', 255);
            $table->string('mime_type', 150);
            $table->string('extension', 10);
            $table->integer('size_bytes');
            $table->string('storage_path', 500);

            $table->string('analysis_status', 20)->default('pending');
            $table->json('analysis_result')->nullable();
            $table->timestamp('analyzed_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('deal_id')->references('id')->on('deals')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['tenant_id', 'deal_id'], 'idx_dcd_tenant_deal');
            $table->index(['tenant_id', 'analysis_status'], 'idx_dcd_tenant_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE deal_contract_documents ADD CONSTRAINT check_dcd_status "
                . "CHECK (analysis_status IN ('pending','analyzing','approved','rejected','failed'))"
            );
            DB::statement(
                "ALTER TABLE deal_contract_documents ADD CONSTRAINT check_dcd_extension "
                . "CHECK (extension IN ('pdf','docx','xlsx','pptx','txt'))"
            );
            DB::statement(
                'ALTER TABLE deal_contract_documents ADD CONSTRAINT check_dcd_size '
                . 'CHECK (size_bytes > 0 AND size_bytes <= 26214400)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_contract_documents');
    }
};
