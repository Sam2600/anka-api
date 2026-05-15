<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rich AI contract-analysis verdicts: add a snapshot column for the previous
 * analysis (so re-uploads can diff against the prior verdict even though the
 * old document row is deleted), and two surface-level convenience columns
 * that the UI reads without unpacking the JSONB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contract_documents', function (Blueprint $table) {
            // Snapshot of the previous row's analysis_result, copied forward
            // on every re-upload so Claude can compute diff_vs_previous.
            // null on the first upload. `json` blueprint type maps to JSONB
            // on Postgres and TEXT on SQLite (test env), matching the existing
            // analysis_result column convention.
            $table->json('previous_analysis')->nullable()->after('analysis_result');

            // Surfaced from analysis_result for fast UI access (gauge, list
            // sorting, future filtering) without parsing the JSONB blob.
            $table->unsignedTinyInteger('overall_score')->nullable()->after('previous_analysis');

            // Which payment pattern Claude detected in the doc — one of
            // monthly_recurring, milestone_based, per_phase, one_time, unknown.
            // Free-form varchar so we can add patterns later without a migration.
            $table->string('detected_payment_pattern', 40)->nullable()->after('overall_score');
        });
    }

    public function down(): void
    {
        Schema::table('deal_contract_documents', function (Blueprint $table) {
            $table->dropColumn(['previous_analysis', 'overall_score', 'detected_payment_pattern']);
        });
    }
};
