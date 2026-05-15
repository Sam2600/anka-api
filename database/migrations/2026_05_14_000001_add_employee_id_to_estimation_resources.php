<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional employee_id to estimation_resources so each feature row
 * can name the person planned to do the work. Nullable: estimation can stay
 * role-only when no specific staffing decision has been made.
 *
 * ON DELETE SET NULL — losing an employee should never cascade and drop
 * the agency's saved estimation rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimation_resources', function (Blueprint $table) {
            $table->uuid('employee_id')->nullable()->after('job_role_id');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('set null');
            $table->index('employee_id', 'idx_estimation_employee');
        });
    }

    public function down(): void
    {
        Schema::table('estimation_resources', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['employee_id']);
                $table->dropIndex('idx_estimation_employee');
            }
            $table->dropColumn('employee_id');
        });
    }
};
