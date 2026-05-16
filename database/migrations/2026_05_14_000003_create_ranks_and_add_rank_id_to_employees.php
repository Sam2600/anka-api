<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 100);
            $table->string('code', 50);
            $table->integer('level')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->unique(['tenant_id', 'code']);
        });

        // Drop the temporary seniority string column in favour of a proper FK.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS check_employees_seniority');
        }
        if (Schema::hasColumn('employees', 'seniority')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('seniority');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->uuid('rank_id')->nullable()->after('capacity_role_id');
            $table->foreign('rank_id')->references('id')->on('ranks')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['rank_id']);
            $table->dropColumn('rank_id');
            $table->string('seniority', 20)->nullable()->after('status');
        });

        Schema::dropIfExists('ranks');
    }
};
