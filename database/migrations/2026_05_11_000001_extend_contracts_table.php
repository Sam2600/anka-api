<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->timestamp('signed_at')->nullable()->after('end_date');
            $table->integer('payment_terms_days')->default(30)->after('signed_at');
            $table->string('po_number', 100)->nullable()->after('payment_terms_days');
            $table->string('billing_contact_name', 255)->nullable()->after('po_number');
            $table->string('billing_email', 255)->nullable()->after('billing_contact_name');
            $table->string('currency', 3)->nullable()->after('billing_email');
            $table->string('tax_jurisdiction', 100)->nullable()->after('currency');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS check_contracts_status');
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT check_contracts_status CHECK (status IN ('Draft','Signed','Active','Completed','Cancelled'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS check_contracts_status');
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT check_contracts_status CHECK (status IN ('Draft','Active','Completed','Cancelled'))");
        }

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'signed_at',
                'payment_terms_days',
                'po_number',
                'billing_contact_name',
                'billing_email',
                'currency',
                'tax_jurisdiction',
            ]);
        });
    }
};
