<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `context_notes` to estimation_versions so each saved snapshot can carry
 * the meeting minutes / chat history that informed it. Used as input to the
 * "Suggest changes from notes" AI flow which produces a structured diff
 * (add/remove/modify) of scope rows and overheads.
 *
 * Nullable: legacy versions don't have notes and we don't backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimation_versions ADD COLUMN context_notes TEXT');
        } else {
            Schema::table('estimation_versions', function (Blueprint $table) {
                $table->text('context_notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimation_versions DROP COLUMN IF EXISTS context_notes');
        } else {
            Schema::table('estimation_versions', function (Blueprint $table) {
                $table->dropColumn('context_notes');
            });
        }
    }
};
