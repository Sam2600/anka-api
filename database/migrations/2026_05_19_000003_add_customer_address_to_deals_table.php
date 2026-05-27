<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer's postal address captured at deal-entry time. Rendered in
 * the Invoice XLSX export's "To," block below the customer name
 * (see the reference template's rows 6-8). Optional — invoices for
 * deals without a captured address render with just the customer
 * name and contact line.
 *
 * Lives on `deals` (not `contracts`) because Sales enters it at the
 * top of the deal lifecycle, before the contract row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->text('customer_address')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('customer_address');
        });
    }
};
