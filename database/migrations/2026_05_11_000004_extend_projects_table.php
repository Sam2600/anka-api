<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('kickoff_date')->nullable()->after('start_date');
            $table->uuid('project_manager_id')->nullable()->after('kickoff_date');

            $table->foreign('project_manager_id')->references('id')->on('employees')->onDelete('set null');
            $table->index('project_manager_id', 'idx_projects_pm');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['project_manager_id']);
            $table->dropIndex('idx_projects_pm');
            $table->dropColumn(['kickoff_date', 'project_manager_id']);
        });
    }
};
