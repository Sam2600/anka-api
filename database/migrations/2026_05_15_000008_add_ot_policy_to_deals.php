<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Captures the customer's overtime / overage expectation at nego time so:
 *
 *   1. ⑦ Profit Calculate can subtract OT cost from project profit when
 *      the customer is NOT paying for it.
 *   2. ④ Estimation can price the deal correctly (an absorbed-OT deal
 *      needs more buffer than a customer-pays-OT deal).
 *   3. ⑤ Contract drafting can render the OT clause from structured
 *      data instead of guessing from a freeform `final_ot_policy` text.
 *
 * Four columns:
 *   - ot_policy_model: which model applies (drives Profit Calculate math)
 *   - ot_rate_per_hour: rate the customer pays (when applicable)
 *   - ot_included_hours_per_month: free hours before customer-pays kicks in
 *     (for the capped model, e.g. Yazaki-style "12 hrs/mo included")
 *   - ot_notes: freeform clarifications for the AI / reviewer
 *
 * Existing `deals.final_ot_policy` (set by Estimation) stays as a
 * freeform notes layer on top of the structured fields. Phase A may
 * deprecate it once Estimation menu reads the structured columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('ot_policy_model', 32)->nullable()->after('client_budget');
            $table->decimal('ot_rate_per_hour', 10, 2)->nullable()->after('ot_policy_model');
            $table->integer('ot_included_hours_per_month')->nullable()->after('ot_rate_per_hour');
            $table->text('ot_notes')->nullable()->after('ot_included_hours_per_month');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_ot_policy_model "
                . "CHECK (ot_policy_model IS NULL OR ot_policy_model IN "
                . "('customer_pays_per_hour','capped_then_customer_pays','absorbed_by_provider','no_overtime_allowed'))"
            );
            DB::statement(
                'ALTER TABLE deals ADD CONSTRAINT check_deals_ot_rate '
                . 'CHECK (ot_rate_per_hour IS NULL OR ot_rate_per_hour >= 0)'
            );
            DB::statement(
                'ALTER TABLE deals ADD CONSTRAINT check_deals_ot_hours '
                . 'CHECK (ot_included_hours_per_month IS NULL OR '
                . '(ot_included_hours_per_month >= 0 AND ot_included_hours_per_month <= 744))'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_ot_hours');
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_ot_rate');
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_ot_policy_model');
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['ot_policy_model', 'ot_rate_per_hour', 'ot_included_hours_per_month', 'ot_notes']);
        });
    }
};
