<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces the "Dropped" lifecycle flag as an orthogonal status flag,
 * replacing the old `status = 'lost'` pattern.
 *
 * The manager's new spec models a dropped deal as "exited the pipeline
 * without closing", which can happen from rank C, B, or A. Knowing the
 * rank at the moment of drop is useful analytics ("we lost this at C"
 * vs "we lost this at A after burning estimation effort").
 *
 * Two new columns:
 *   - lifecycle_status: 'active' | 'dropped' (default 'active')
 *   - dropped_at_stage: nullable, captures the deals.status value at
 *     the moment of drop ('lead' | 'qualified' | 'negotiation')
 *
 * Backfill: existing `status='lost'` rows get lifecycle_status='dropped'
 * BUT we keep status='lost' for now — the CHECK constraint update to
 * drop 'lost' from the allowed set is in Phase B-breaking (a later
 * migration), once the frontend and API both stop reading/writing 'lost'.
 *
 * SQLite (test env) doesn't get a CHECK constraint; the application
 * layer enforces the allowed values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('lifecycle_status', 20)->default('active')->after('status');
            $table->string('dropped_at_stage', 20)->nullable()->after('lifecycle_status');
            $table->timestamp('dropped_at', 0)->nullable()->after('dropped_at_stage');
        });

        DB::table('deals')->where('status', 'lost')->update([
            'lifecycle_status' => 'dropped',
            'dropped_at_stage' => null,
            'dropped_at' => DB::raw('COALESCE(lost_at, updated_at)'),
        ]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_lifecycle_status "
                . "CHECK (lifecycle_status IN ('active','dropped'))"
            );
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_dropped_at_stage "
                . "CHECK (dropped_at_stage IS NULL OR dropped_at_stage IN ('lead','qualified','negotiation'))"
            );
            DB::statement('CREATE INDEX idx_deals_lifecycle ON deals (tenant_id, lifecycle_status)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_deals_lifecycle');
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_dropped_at_stage');
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_lifecycle_status');
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_status', 'dropped_at_stage', 'dropped_at']);
        });
    }
};
