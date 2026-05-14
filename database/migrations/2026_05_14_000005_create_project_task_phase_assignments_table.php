<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_phase_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_assignment_id');
            $table->string('phase_code', 30);
            $table->string('phase_name', 100);
            $table->integer('phase_order');
            $table->decimal('estimated_hours', 8, 2)->default(0);
            $table->uuid('assignee_id')->nullable();
            $table->string('assignment_source', 20)->default('ai');
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->string('status', 20)->default('未着手');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('task_assignment_id')->references('id')->on('project_task_assignments')->onDelete('cascade');
            $table->foreign('assignee_id')->references('id')->on('employees')->onDelete('set null');
            $table->unique(['task_assignment_id', 'phase_code']);
            $table->index('tenant_id', 'idx_ptpa_tenant');
            $table->index('assignee_id', 'idx_ptpa_assignee');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE project_task_phase_assignments ADD CONSTRAINT check_ptpa_phase_code ".
                "CHECK (phase_code IN ('development','requirement','system_arch','basic_doc','detail_doc','unit_test','combine_test','system_test'))"
            );
            DB::statement(
                "ALTER TABLE project_task_phase_assignments ADD CONSTRAINT check_ptpa_status ".
                "CHECK (status IN ('未着手','進行中','完了'))"
            );
            DB::statement(
                "ALTER TABLE project_task_phase_assignments ADD CONSTRAINT check_ptpa_source ".
                "CHECK (assignment_source IN ('ai','manual'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_phase_assignments');
    }
};
