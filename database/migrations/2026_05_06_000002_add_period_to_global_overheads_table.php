<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_overheads', function (Blueprint $table) {
            // NULL = overhead applies to every month (e.g. fixed Office Rent).
            // When both are set, the row applies only to that specific month/year.
            $table->smallInteger('effective_month')->nullable()->after('monthly_cost');
            $table->smallInteger('effective_year')->nullable()->after('effective_month');
        });
    }

    public function down(): void
    {
        Schema::table('global_overheads', function (Blueprint $table) {
            $table->dropColumn(['effective_month', 'effective_year']);
        });
    }
};
