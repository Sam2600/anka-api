<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_team_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('project_id');
            $table->uuid('employee_id');
            $table->decimal('allocated_hours', 10, 2)->default(0);
            $table->string('assignment_source', 20)->default('manual');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->unique(['project_id', 'employee_id']);
            $table->index('employee_id', 'idx_project_team_emp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_team_assignments');
    }
};
