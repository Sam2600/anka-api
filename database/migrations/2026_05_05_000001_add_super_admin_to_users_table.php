<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the NOT NULL constraint so super admin can have no tenant.
            $table->foreignUuid('tenant_id')->nullable()->change();

            $table->boolean('is_super_admin')->default(false)->after('system_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
            $table->foreignUuid('tenant_id')->nullable(false)->change();
        });
    }
};
