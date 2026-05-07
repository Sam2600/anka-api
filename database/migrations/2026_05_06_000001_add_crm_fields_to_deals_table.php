<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->date('expected_close_date')->nullable()->after('win_probability');
            $table->string('lead_source', 50)->nullable()->after('expected_close_date');
            $table->string('contact_name', 255)->nullable()->after('client');
            $table->string('contact_email', 255)->nullable()->after('contact_name');
            $table->string('contact_phone', 50)->nullable()->after('contact_email');
            $table->string('win_reason', 500)->nullable()->after('won_at');
            $table->string('loss_reason', 500)->nullable()->after('lost_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE deals ADD CONSTRAINT check_deals_lead_source " .
                "CHECK (lead_source IS NULL OR lead_source IN " .
                "('inbound','referral','cold_outreach','social','event','partner','other'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS check_deals_lead_source');
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'expected_close_date',
                'lead_source',
                'contact_name',
                'contact_email',
                'contact_phone',
                'win_reason',
                'loss_reason',
            ]);
        });
    }
};
