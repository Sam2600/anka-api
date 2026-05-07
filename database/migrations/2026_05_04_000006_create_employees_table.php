<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('department_id')->nullable();
            $table->uuid('job_role_id')->nullable();
            $table->string('name', 255);
            $table->text('role')->nullable();
            $table->string('role_name', 255)->nullable();
            $table->string('capacity_role', 50)->nullable();
            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->integer('workable_hours')->default(160);
            // cost_per_hour is GENERATED in PostgreSQL — added below via raw SQL
            $table->string('status', 50)->default('Active');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('job_role_id')->references('id')->on('roles')->onDelete('set null');

            $table->index(['tenant_id', 'status'], 'idx_employees_tenant_status');
            $table->index('job_role_id', 'idx_employees_job_role');
            $table->index(['tenant_id', 'capacity_role'], 'idx_employees_capacity_role');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees ADD COLUMN cost_per_hour numeric(10,4) GENERATED ALWAYS AS (monthly_salary / NULLIF(workable_hours, 0)) STORED');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT check_employees_capacity_role CHECK (capacity_role IN ('frontend','backend','pm','qa','design'))");
            DB::statement("ALTER TABLE employees ADD CONSTRAINT check_employees_status CHECK (status IN ('Active','On Leave','Terminated'))");
        } else {
            // SQLite — add cost_per_hour as a regular column (not generated)
            DB::statement('ALTER TABLE employees ADD COLUMN cost_per_hour decimal(10,4) DEFAULT 0');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
