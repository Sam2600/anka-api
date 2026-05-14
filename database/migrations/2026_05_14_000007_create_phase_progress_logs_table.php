<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phase_progress_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('phase_assignment_id');
            $table->uuid('employee_id');
            $table->date('log_date');
            $table->decimal('progress_hours', 5, 2);
            $table->decimal('used_hours', 5, 2);
            $table->text('note')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('phase_assignment_id')->references('id')->on('project_task_phase_assignments')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('restrict');

            $table->unique(['phase_assignment_id', 'log_date', 'employee_id'], 'uq_phase_log_day_employee');
            $table->index(['tenant_id', 'log_date'], 'idx_phase_log_tenant_date');
            $table->index(['employee_id', 'log_date'], 'idx_phase_log_employee_date');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE phase_progress_logs ADD CONSTRAINT check_ppl_progress_nonneg CHECK (progress_hours >= 0)');
            DB::statement('ALTER TABLE phase_progress_logs ADD CONSTRAINT check_ppl_used_nonneg CHECK (used_hours >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phase_progress_logs');
    }
};
