<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolidate the deal stage taxonomy.
 *
 * Old stages: lead, inquiry, opportunity, proposal, contract, won, lost
 * New stages: lead, qualified, proposal, negotiation, won, lost
 *
 * Mappings:
 *   inquiry      → lead         (duplicate of lead — same probability)
 *   opportunity  → qualified    (clearer label for "BANT-confirmed")
 *   contract     → negotiation  (avoids name clash with the post-win Contract entity)
 *
 * SQLite (used in tests) doesn't enforce CHECK constraints the same way —
 * the data UPDATE still runs there so test fixtures stay consistent, but the
 * constraint swap is Postgres-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Remap existing rows. Safe everywhere.
        DB::table('deals')->where('status', 'inquiry')->update(['status' => 'lead']);
        DB::table('deals')->where('status', 'opportunity')->update(['status' => 'qualified']);
        DB::table('deals')->where('status', 'contract')->update(['status' => 'negotiation']);

        if (DB::getDriverName() === 'pgsql') {
            // 2. Swap the CHECK constraint to enforce only the new vocabulary.
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_status');
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_status "
                . "CHECK (status IN ('lead','qualified','proposal','negotiation','won','lost'))"
            );

            // 3. Update the column default so newly-inserted rows without an
            //    explicit status land in 'lead' instead of 'inquiry'.
            DB::statement("ALTER TABLE deals ALTER COLUMN status SET DEFAULT 'lead'");
        }
    }

    public function down(): void
    {
        // Reverse mappings — there's no perfect reverse for `lead → inquiry`
        // since two old states collapsed into one. We pick `inquiry` because
        // it was the historical default; if you ran this rollback you'll
        // want to manually classify which leads were originally inquiries.
        DB::table('deals')->where('status', 'lead')->update(['status' => 'inquiry']);
        DB::table('deals')->where('status', 'qualified')->update(['status' => 'opportunity']);
        DB::table('deals')->where('status', 'negotiation')->update(['status' => 'contract']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_status');
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_status "
                . "CHECK (status IN ('lead','inquiry','opportunity','proposal','contract','won','lost'))"
            );
            DB::statement("ALTER TABLE deals ALTER COLUMN status SET DEFAULT 'inquiry'");
        }
    }
};
