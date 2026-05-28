<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_team_assignments', function (Blueprint $table) {
            $table->text('monthly_allocation')->nullable()->after('allocated_hours');
            $table->date('team_start_date')->nullable()->after('monthly_allocation');
        });
    }

    public function down(): void
    {
        Schema::table('project_team_assignments', function (Blueprint $table) {
            $table->dropColumn(['monthly_allocation', 'team_start_date']);
        });
    }
};
