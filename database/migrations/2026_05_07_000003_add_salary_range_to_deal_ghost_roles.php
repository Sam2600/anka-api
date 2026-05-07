<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE deal_ghost_roles ADD COLUMN min_monthly_salary decimal(12,2) DEFAULT 0');
        DB::statement('ALTER TABLE deal_ghost_roles ADD COLUMN max_monthly_salary decimal(12,2) DEFAULT 0');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deal_ghost_roles ADD CONSTRAINT check_ghost_min_salary CHECK (min_monthly_salary >= 0)');
            DB::statement('ALTER TABLE deal_ghost_roles ADD CONSTRAINT check_ghost_max_salary CHECK (max_monthly_salary >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropColumns('deal_ghost_roles', ['min_monthly_salary', 'max_monthly_salary']);
    }
};
