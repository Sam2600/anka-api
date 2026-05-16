<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_task_phase_assignments', function (Blueprint $table) {
            $table->decimal('start_day_hours', 5, 2)->nullable()->after('estimated_hours');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE project_task_phase_assignments ADD CONSTRAINT check_ptpa_start_day_hours ".
                "CHECK (start_day_hours IS NULL OR (start_day_hours >= 0 AND start_day_hours <= 8))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE project_task_phase_assignments DROP CONSTRAINT IF EXISTS check_ptpa_start_day_hours");
        }

        Schema::table('project_task_phase_assignments', function (Blueprint $table) {
            $table->dropColumn('start_day_hours');
        });
    }
};
