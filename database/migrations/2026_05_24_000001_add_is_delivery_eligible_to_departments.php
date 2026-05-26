<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks each department as eligible (or not) for customer-delivery staffing.
 *
 * Why: the `Employee::scopeIdleAndFullTime` scope previously returned every
 * Active, full-time employee with zero project assignments — regardless of
 * department. That meant Sales/HR/Finance staff who carry a `pm` capacity_role
 * (granted so they can run internal initiatives, manage invoices, etc.)
 * appeared in the AI Team Builder's idle pool and could be picked as the
 * project's project manager. Downstream `assign-tasks` would then route doc
 * phases (basic_doc / requirement / detail_doc) to a non-delivery employee,
 * which is wrong: customer-delivery projects should staff from departments
 * that actually do delivery work.
 *
 * Default = true. Existing tenants keep all departments as delivery-eligible
 * on upgrade; non-delivery departments must be explicitly flagged false by
 * the tenant admin (or by the seeder for demo data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('is_delivery_eligible')
                ->default(true)
                ->after('headcount');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('is_delivery_eligible');
        });
    }
};
