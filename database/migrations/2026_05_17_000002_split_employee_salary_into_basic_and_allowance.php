<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ①.2: salary must be split into Basic Salary + Allowance fee as
 * separate fields. Today employees has one `monthly_salary` column;
 * this migration adds the two structural fields and backfills the
 * existing total into `basic_salary` (with `allowance` defaulting to
 * zero — no way to retroactively guess the split).
 *
 * Soft cutover: `monthly_salary` is intentionally LEFT IN PLACE and
 * maintained by the Employee model's save hook
 * (monthly_salary = basic_salary + allowance) so every existing
 * reader — estimation, profit calc, AI team builder, forecast,
 * dashboard, ghost-role brackets — keeps working unchanged. The
 * Postgres `cost_per_hour` GENERATED column (defined off
 * monthly_salary) also keeps working. A phase-2 migration may drop
 * the column once enough time passes to verify nothing still writes
 * to it directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('basic_salary', 12, 2)->default(0)->after('monthly_salary');
            $table->decimal('allowance', 12, 2)->default(0)->after('basic_salary');
        });

        // Backfill: every existing row's monthly_salary becomes basic_salary,
        // allowance defaults to 0. Operators can split the existing total
        // into basic + allowance later via the Employee edit form.
        DB::table('employees')->update([
            'basic_salary' => DB::raw('monthly_salary'),
            'allowance' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['basic_salary', 'allowance']);
        });
    }
};
