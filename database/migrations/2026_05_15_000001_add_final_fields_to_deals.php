<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Estimation-handoff fields to the deals table.
 *
 * These columns are populated by the Estimation menu (④ in the manager's
 * System Flow spec) once a customer has confirmed the estimate. They
 * MUST be set before a deal becomes contract-eligible — the Project
 * Pipeline menu (③ Nego + ⑤ Contract) reads them when AI-drafting a
 * contract.
 *
 * All columns are nullable at the DB level. Required-ness is enforced at
 * the service layer (ContractDraftService rejects generation when any of
 * the required values is null), keeping this migration safe to apply on
 * existing tenants that haven't run estimation against their old deals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('final_monthly_fee', 14, 2)->nullable()->after('estimated_gross_profit');
            $table->decimal('final_installation_fee', 14, 2)->nullable()->after('final_monthly_fee');
            $table->integer('final_contract_months')->nullable()->after('final_installation_fee');
            $table->text('final_ot_policy')->nullable()->after('final_contract_months');
            $table->integer('final_support_hours_per_month')->nullable()->after('final_ot_policy');
            $table->text('final_team_summary')->nullable()->after('final_support_hours_per_month');
            $table->char('final_currency', 3)->nullable()->after('final_team_summary');
            $table->timestamp('final_confirmed_at')->nullable()->after('final_currency');
            $table->string('suggested_template_variant', 64)->nullable()->after('final_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'final_monthly_fee',
                'final_installation_fee',
                'final_contract_months',
                'final_ot_policy',
                'final_support_hours_per_month',
                'final_team_summary',
                'final_currency',
                'final_confirmed_at',
                'suggested_template_variant',
            ]);
        });
    }
};
