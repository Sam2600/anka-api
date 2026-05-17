<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-draft override of the Provider signatory shown on the contract
 * PDF. Tenant settings hold the default; if the salesperson wants a
 * different signer for one specific contract (e.g. a director signs
 * the big-ticket deal but the sales manager signs everyday ones), the
 * wizard sets these on the draft and they win over the tenant default.
 *
 * Both nullable. Null → fall back to tenant.signatory_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contract_drafts', function (Blueprint $table) {
            $table->string('signatory_name_override', 255)->nullable()->after('finalized_by_user_id');
            $table->string('signatory_title_override', 255)->nullable()->after('signatory_name_override');
        });
    }

    public function down(): void
    {
        Schema::table('deal_contract_drafts', function (Blueprint $table) {
            $table->dropColumn(['signatory_name_override', 'signatory_title_override']);
        });
    }
};
