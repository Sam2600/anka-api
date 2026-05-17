<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->uuid('capacity_role_id')->nullable()->after('capacity_role');
            $table->foreign('capacity_role_id')->references('id')->on('capacity_roles')->onDelete('set null');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS check_employees_capacity_role');
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['capacity_role_id']);
            $table->dropColumn('capacity_role_id');
        });
    }
};
