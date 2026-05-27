<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimation_versions ADD COLUMN sheet_function_list JSONB');
            DB::statement('ALTER TABLE estimation_versions ADD COLUMN sheet_manhour_detail JSONB');
            DB::statement('ALTER TABLE estimation_versions ADD COLUMN sheet_milestone JSONB');
            DB::statement('ALTER TABLE estimation_versions ADD COLUMN sheet_team_structure JSONB');
        } else {
            Schema::table('estimation_versions', function (Blueprint $table) {
                $table->json('sheet_function_list')->nullable();
                $table->json('sheet_manhour_detail')->nullable();
                $table->json('sheet_milestone')->nullable();
                $table->json('sheet_team_structure')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimation_versions DROP COLUMN IF EXISTS sheet_function_list');
            DB::statement('ALTER TABLE estimation_versions DROP COLUMN IF EXISTS sheet_manhour_detail');
            DB::statement('ALTER TABLE estimation_versions DROP COLUMN IF EXISTS sheet_milestone');
            DB::statement('ALTER TABLE estimation_versions DROP COLUMN IF EXISTS sheet_team_structure');
        } else {
            Schema::table('estimation_versions', function (Blueprint $table) {
                $table->dropColumn([
                    'sheet_function_list',
                    'sheet_manhour_detail',
                    'sheet_milestone',
                    'sheet_team_structure',
                ]);
            });
        }
    }
};
