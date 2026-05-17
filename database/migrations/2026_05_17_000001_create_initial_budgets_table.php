<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Year-scopes the Initial Budget. Spec ①.3 requires "at the start of each
 * year, the user declares an Initial Budget = target profit for that year"
 * — meaning multiple years must coexist (2026 = 1B, 2027 = 1.5B, ...).
 *
 * The singleton `company_settings.annual_initial_budget` can't represent
 * that — one slot, no fiscal_year column. This migration introduces
 * `initial_budgets` keyed by (tenant_id, fiscal_year) and backfills the
 * existing singleton value into the current fiscal year so no data is
 * lost.
 *
 * Soft cutover: the `company_settings.annual_initial_budget` column is
 * intentionally LEFT IN PLACE for one phase so any consumer that hasn't
 * been migrated yet still reads a sensible value. A follow-up migration
 * will drop the column once everything reads from `initial_budgets`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('initial_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->smallInteger('fiscal_year');
            $table->decimal('amount', 18, 2);
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'fiscal_year']);
            $table->index('tenant_id');

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('created_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        // Backfill: copy each tenant's existing singleton annual_initial_budget
        // into a row for the current fiscal year. Preserves prior data so no
        // tenant loses their declared target on the cutover.
        $currentYear = (int) date('Y');
        $now = now();
        $settings = DB::table('company_settings')
            ->whereNotNull('annual_initial_budget')
            ->get(['tenant_id', 'annual_initial_budget']);

        foreach ($settings as $row) {
            if (! $row->tenant_id) {
                continue;
            }
            DB::table('initial_budgets')->insert([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $row->tenant_id,
                'fiscal_year' => $currentYear,
                'amount' => $row->annual_initial_budget,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('initial_budgets');
    }
};
