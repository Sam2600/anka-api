<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B-breaking: removes the legacy 'lost' status value.
 *
 * Phase B added the lifecycle_status flag and backfilled status='lost'
 * rows to lifecycle_status='dropped' (keeping status='lost' so the old
 * /lose endpoint kept working through the deprecation window). This
 * migration completes the cutover:
 *
 *   1. Rewrite remaining status='lost' rows → status='qualified'
 *      (they already have lifecycle_status='dropped' from the Phase B
 *      backfill, so they continue to render correctly on the Kanban
 *      as greyed cards in the B column under "Show dropped").
 *      'qualified' is a safer target than 'lead' because most lost
 *      deals were past the initial-contact stage when they died.
 *
 *   2. Drop 'lost' from the CHECK constraint so future writes can't
 *      reintroduce it.
 *
 * SQLite (test env) doesn't enforce the CHECK constraint the same way;
 * the application layer is the authority there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deals')->where('status', 'lost')->update([
            'status' => 'qualified',
            // Defensive — Phase B should have backfilled this, but
            // re-set if missing so Kanban filters work.
            'lifecycle_status' => 'dropped',
        ]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_status');
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_status "
                . "CHECK (status IN ('lead','qualified','negotiation','won'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_status');
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_status "
                . "CHECK (status IN ('lead','qualified','proposal','negotiation','won','lost'))"
            );
        }
        // Note: this rollback does NOT restore the original status='lost'
        // values on the migrated rows, because the dropped_at_stage data
        // is more accurate for analytics and we don't want to lose it.
    }
};
