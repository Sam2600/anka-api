<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer requirements collected progressively during nego (③).
 *
 * Per the manager's spec, the contract must explicitly capture:
 *   - Customer support obligations (devices, environments, accounts)
 *   - Out-of-scope policy (additional charges)
 *   - Working hours (esp. for offshore time-zone clarity)
 *   - Testing range (browsers, OS versions)
 *
 * These were previously asked as wizard step-1 questions on the
 * `engineer_dispatch` template variant only — too late for ④ Estimation
 * to use when pricing the deal, and not captured at all for cloud_backup
 * or managed_hosting deals.
 *
 * All four are nullable text. The salesperson fills them progressively
 * as customer conversations evolve. By contract drafting time, whatever
 * is filled flows into the AI prompt as DEAL CONTEXT; missing values
 * become {{TODO}} markers in the rendered draft for the operator to
 * resolve in step 2 of the wizard.
 *
 * Locked at rank A/S (handled in Deal::FIELDS_LOCKED_IN_A_OR_S, not
 * here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->text('customer_support_obligations')->nullable()->after('ot_notes');
            $table->text('out_of_scope_policy')->nullable()->after('customer_support_obligations');
            $table->text('working_hours')->nullable()->after('out_of_scope_policy');
            $table->text('testing_range')->nullable()->after('working_hours');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'customer_support_obligations',
                'out_of_scope_policy',
                'working_hours',
                'testing_range',
            ]);
        });
    }
};
