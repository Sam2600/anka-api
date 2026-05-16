<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-tenant logo path. Renders at the top of every contract PDF
 * (and inline summary in the customer-facing email). Nullable — tenants
 * without a logo fall back to config('contract.provider_fallback.logo_path')
 * so existing demo data still renders.
 *
 * The path stored here is RELATIVE to the public disk root
 * (storage/app/public/...). Resolve with Storage::disk('public')->path()
 * for filesystem access or Storage::disk('public')->url() for HTTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('logo_path', 500)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
