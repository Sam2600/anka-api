<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapse the deal pipeline from 6 stages to 5 (4 active + lost).
 *
 * Old stages: lead, qualified, proposal, negotiation, won, lost
 * New stages: lead, qualified, negotiation, won, lost
 *
 * UI rank labels (frontend only):
 *   lead        → C
 *   qualified   → B   (Qualified+Proposal merged)
 *   negotiation → A   (contract-document gate lives here)
 *   won         → S
 *   lost        → D
 *
 * The contract document upload + Claude analysis happens while a deal is in
 * `negotiation`; once a document is approved by the analyser the deal
 * auto-transitions to `won` via the existing win_deal() stored procedure.
 *
 * SQLite (tests) doesn't enforce CHECK constraints the same way, so only the
 * UPDATE runs there. The constraint swap is Postgres-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deals')->where('status', 'proposal')->update(['status' => 'qualified']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_status');
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_status "
                . "CHECK (status IN ('lead','qualified','negotiation','won','lost'))"
            );
        }
    }

    public function down(): void
    {
        // No safe reverse for the merge — every old `proposal` row is now
        // `qualified` and we can't tell them apart. Restore the wider
        // constraint so a manual re-classification is possible if needed.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_status');
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_status "
                . "CHECK (status IN ('lead','qualified','proposal','negotiation','won','lost'))"
            );
        }
    }
};
