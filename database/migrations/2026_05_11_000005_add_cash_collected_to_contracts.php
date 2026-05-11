<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split contracts.revenue_recognized into two semantics:
 *
 *   - cash_collected      → cash basis: cumulative payments received
 *                           (what the old revenue_recognized actually meant)
 *
 *   - revenue_recognized  → accrual basis (GOING FORWARD):
 *                           cumulative value of milestones the client has Accepted
 *
 * Backfill strategy: we copy existing revenue_recognized into cash_collected so
 * historical cash totals are preserved. We do NOT zero out revenue_recognized —
 * for existing data it still reflects cash-basis numbers, but new milestone
 * Accept events start adding accrual value on top. This is a one-time semantic
 * shift; documented in the contract detail page UI so users see both numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('cash_collected', 14, 2)->default(0)->after('revenue_recognized');
        });

        // Backfill from existing cash-basis revenue_recognized.
        DB::statement('UPDATE contracts SET cash_collected = revenue_recognized');
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('cash_collected');
        });
    }
};
