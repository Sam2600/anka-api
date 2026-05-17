<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ④.G — "When the Estimate Doc is approved, send it to the
 * customer by email." Adds the columns needed to track that send:
 *
 *   sent_at        — when the estimate XLSX was emailed
 *   sent_to_email  — the address it went to (snapshot at send time;
 *                    deal.contact_email may change later)
 *
 * Both nullable. Backfilled to null (no prior sends to record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimation_versions', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('xlsx_path');
            $table->string('sent_to_email', 255)->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('estimation_versions', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'sent_to_email']);
        });
    }
};
