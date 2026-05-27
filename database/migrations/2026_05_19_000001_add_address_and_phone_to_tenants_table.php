<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant postal address + phone — used by the new Invoice menu's
 * XLSX export to render the company block in the top-left of the
 * invoice template (above the customer "To," block). Editable via
 * Org → Company settings.
 *
 * Both nullable. Empty values render as blank lines in the PDF/XLSX
 * — preserves existing tenants until they fill them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('address')->nullable()->after('signatory_title');
            $table->string('phone', 50)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['address', 'phone']);
        });
    }
};
