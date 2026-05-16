<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE project_task_assignments DROP CONSTRAINT IF EXISTS check_pta_status');
            DB::statement('ALTER TABLE project_task_assignments DROP CONSTRAINT IF EXISTS check_pta_source');
        }

        Schema::table('project_task_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('project_task_assignments', 'assignee_id')) {
                $table->dropForeign(['assignee_id']);
                $table->dropIndex('idx_project_task_assignee');
            }
        });

        Schema::table('project_task_assignments', function (Blueprint $table) {
            $columns = [];
            foreach (['assignee_id', 'assignment_source', 'planned_start', 'planned_end', 'actual_start', 'actual_end', 'status'] as $col) {
                if (Schema::hasColumn('project_task_assignments', $col)) {
                    $columns[] = $col;
                }
            }
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_task_assignments', function (Blueprint $table) {
            $table->uuid('assignee_id')->nullable();
            $table->string('assignment_source', 20)->default('ai');
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->string('status', 20)->default('未着手');

            $table->foreign('assignee_id')->references('id')->on('employees')->onDelete('set null');
            $table->index('assignee_id', 'idx_project_task_assignee');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE project_task_assignments ADD CONSTRAINT check_pta_status ".
                "CHECK (status IN ('未着手','進行中','完了'))"
            );
            DB::statement(
                "ALTER TABLE project_task_assignments ADD CONSTRAINT check_pta_source ".
                "CHECK (assignment_source IN ('ai','manual'))"
            );
        }
    }
};
