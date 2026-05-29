<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_team_assignments', function (Blueprint $table) {
            $table->date('team_end_date')->nullable()->after('team_start_date');
            $table->index(
                ['employee_id', 'team_start_date', 'team_end_date'],
                'idx_pta_employee_window'
            );
        });
    }

    public function down(): void
    {
        Schema::table('project_team_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_pta_employee_window');
            $table->dropColumn('team_end_date');
        });
    }
};
