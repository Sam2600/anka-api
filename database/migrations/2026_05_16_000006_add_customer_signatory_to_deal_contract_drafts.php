<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-side signatory captured at contract-drafting time. The deal's
 * contact_* fields hold the day-to-day liaison (often procurement / sales
 * on the customer side), which is rarely the same person who signs.
 *
 * All three nullable — leaving any blank renders an underscore line on
 * the PDF for the customer to fill in on signing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contract_drafts', function (Blueprint $table) {
            $table->string('customer_signatory_name', 255)->nullable()->after('signatory_title_override');
            $table->string('customer_signatory_title', 255)->nullable()->after('customer_signatory_name');
            $table->date('customer_signed_date')->nullable()->after('customer_signatory_title');
        });
    }

    public function down(): void
    {
        Schema::table('deal_contract_drafts', function (Blueprint $table) {
            $table->dropColumn([
                'customer_signatory_name',
                'customer_signatory_title',
                'customer_signed_date',
            ]);
        });
    }
};
