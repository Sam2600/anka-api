<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant authorized signatory — the person on Provider's side whose
 * name + title appear in the contract PDF's signature block. Editable
 * via Org → Company settings.
 *
 * Both nullable. Empty → PDF renders the company name only, with a blank
 * "Signed by" line. The per-draft override on deal_contract_drafts wins
 * when set; this is the tenant-wide default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('signatory_name', 255)->nullable()->after('logo_path');
            $table->string('signatory_title', 255)->nullable()->after('signatory_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['signatory_name', 'signatory_title']);
        });
    }
};
