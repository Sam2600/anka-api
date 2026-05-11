<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->default(0)->after('tax');
            $table->timestamp('issued_at')->nullable()->after('paid_at');
            $table->string('sent_to_email', 255)->nullable()->after('issued_at');
            $table->integer('reminder_sent_count')->default(0)->after('sent_to_email');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS check_invoices_status');
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT check_invoices_status CHECK (status IN ('Draft','Pending','Partially Paid','Paid','Overdue','Cancelled'))");
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT check_invoices_paid_amount CHECK (paid_amount >= 0 AND paid_amount <= total)');

            // Backfill: invoices already Paid should have paid_amount = total
            DB::statement("UPDATE invoices SET paid_amount = total WHERE status = 'Paid'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS check_invoices_paid_amount');
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS check_invoices_status');
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT check_invoices_status CHECK (status IN ('Draft','Pending','Paid','Overdue','Cancelled'))");
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'issued_at', 'sent_to_email', 'reminder_sent_count']);
        });
    }
};
