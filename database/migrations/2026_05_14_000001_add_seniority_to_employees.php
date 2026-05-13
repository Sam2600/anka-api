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
            $table->string('seniority', 20)->nullable()->after('status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE employees ADD CONSTRAINT check_employees_seniority ".
                "CHECK (seniority IS NULL OR seniority IN ('Junior','Mid','Senior','Lead'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS check_employees_seniority');
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('seniority');
        });
    }
};
