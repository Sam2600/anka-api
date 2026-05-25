<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phase_progress_logs', function (Blueprint $table) {
            $table->decimal('late_hours', 5, 2)->default(0)->after('used_hours');
        });

        // Backfill existing rows: late_hours = max(0, used_hours - progress_hours)
        DB::table('phase_progress_logs')
            ->whereRaw('used_hours > progress_hours')
            ->update(['late_hours' => DB::raw('ROUND(used_hours - progress_hours, 2)')]);
    }

    public function down(): void
    {
        Schema::table('phase_progress_logs', function (Blueprint $table) {
            $table->dropColumn('late_hours');
        });
    }
};
