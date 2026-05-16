<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops customer_signed_date from deal_contract_drafts.
 *
 * The field was added in 2026_05_16_000006 alongside the customer-side
 * signatory name + title, but pre-filling the customer's signing date is
 * meaningless — we send the contract before they decide when to sign it.
 * The PDF now always prints a blank '____' line on the User-side Date
 * row for the customer to fill in by hand when they actually sign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contract_drafts', function (Blueprint $table) {
            $table->dropColumn('customer_signed_date');
        });
    }

    public function down(): void
    {
        Schema::table('deal_contract_drafts', function (Blueprint $table) {
            $table->date('customer_signed_date')->nullable()->after('customer_signatory_title');
        });
    }
};
