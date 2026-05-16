<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('annual_initial_budget', 18, 2)
                ->default(1000000000)
                ->after('yearly_fixed_cost');
        });

        DB::table('company_settings')
            ->whereNull('annual_initial_budget')
            ->update(['annual_initial_budget' => 1000000000]);
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('annual_initial_budget');
        });
    }
};
