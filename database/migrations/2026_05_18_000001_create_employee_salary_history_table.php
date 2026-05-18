<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Spec ②.1.B — Employee Cost Price must be managed per month (TargetMonth)
 * so a salary change in July doesn't retroactively rewrite the
 * January cost calculation. Today there's one `employees.monthly_salary`
 * slot and a salary edit overwrites history.
 *
 * This table holds the salary timeline per employee: one row per
 * (employee, target_month). The Employee record's basic_salary +
 * allowance + cost_per_hour are kept as the current month's
 * denormalized cache so legacy readers (estimation, financial,
 * forecast) keep working unchanged. New code that needs a historical
 * rate calls Employee::salaryForDate($date) which looks up the
 * most-recent-on-or-before row.
 *
 * Backfill: every existing employee gets one row at the first day of
 * the current month with their current basic_salary + allowance +
 * cost_per_hour + workable_hours snapshotted. No data is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id');
            // First-of-month date. The row applies from this month forward
            // until a later row supersedes it.
            $table->date('target_month');
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('allowance', 12, 2)->default(0);
            // Snapshot of cost_per_hour at row creation time. NOT a generated
            // column — captured so the historical rate is stable even if
            // workable_hours on the Employee record changes later.
            $table->decimal('cost_per_hour', 10, 4);
            $table->integer('workable_hours')->default(160);
            $table->text('notes')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'target_month']);
            $table->index(['tenant_id', 'employee_id', 'target_month']);

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('employee_id')
                ->references('id')->on('employees')
                ->cascadeOnDelete();
            $table->foreign('created_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        // Backfill — one starting row per existing employee at the first of
        // this month, using their current values. Skips rows where the
        // employee is missing required data (defensive against legacy seeds).
        $thisMonth = now()->startOfMonth()->toDateString();
        $now = now();

        $employees = DB::table('employees')->get([
            'id', 'tenant_id', 'basic_salary', 'allowance', 'cost_per_hour', 'workable_hours',
        ]);

        foreach ($employees as $emp) {
            if (! $emp->tenant_id) {
                continue;
            }
            DB::table('employee_salary_history')->insert([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $emp->tenant_id,
                'employee_id' => $emp->id,
                'target_month' => $thisMonth,
                'basic_salary' => $emp->basic_salary ?? 0,
                'allowance' => $emp->allowance ?? 0,
                'cost_per_hour' => $emp->cost_per_hour ?? 0,
                'workable_hours' => $emp->workable_hours ?? 160,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_history');
    }
};
