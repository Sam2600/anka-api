<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimation_versions ADD COLUMN xlsx_path VARCHAR(512)');
        } else {
            Schema::table('estimation_versions', function ($table) {
                $table->string('xlsx_path', 512)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimation_versions DROP COLUMN IF EXISTS xlsx_path');
        } else {
            Schema::table('estimation_versions', function ($table) {
                $table->dropColumn('xlsx_path');
            });
        }
    }
};
