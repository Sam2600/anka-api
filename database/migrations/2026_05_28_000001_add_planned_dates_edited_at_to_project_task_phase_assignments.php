<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_task_phase_assignments', function (Blueprint $table) {
            $table->timestamp('planned_dates_edited_at')->nullable()->after('planned_end');
        });
    }

    public function down(): void
    {
        Schema::table('project_task_phase_assignments', function (Blueprint $table) {
            $table->dropColumn('planned_dates_edited_at');
        });
    }
};
