<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('project_id');
            $table->integer('row_no');
            $table->string('function_id', 100)->nullable();
            $table->string('function_name', 255);
            $table->string('category', 50)->nullable();
            $table->string('offshore', 20)->nullable();
            $table->string('difficulty', 20);
            $table->decimal('total_hours', 8, 2)->default(0);
            $table->uuid('assignee_id')->nullable();
            $table->string('assignment_source', 20)->default('ai');
            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->string('status', 20)->default('未着手');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('assignee_id')->references('id')->on('employees')->onDelete('set null');
            $table->unique(['project_id', 'row_no']);
            $table->index('tenant_id', 'idx_project_task_tenant');
            $table->index('assignee_id', 'idx_project_task_assignee');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE project_task_assignments ADD CONSTRAINT check_pta_difficulty ".
                "CHECK (difficulty IN ('簡単','普通','難しい'))"
            );
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

    public function down(): void
    {
        Schema::dropIfExists('project_task_assignments');
    }
};
