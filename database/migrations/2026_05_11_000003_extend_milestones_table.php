<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->text('acceptance_criteria')->nullable()->after('amount');
            $table->timestamp('accepted_at')->nullable()->after('completed_at');
            $table->string('accepted_by_client', 255)->nullable()->after('accepted_at');
            $table->integer('sequence_number')->nullable()->after('accepted_by_client');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE milestones DROP CONSTRAINT IF EXISTS check_milestones_status');
            DB::statement("ALTER TABLE milestones ADD CONSTRAINT check_milestones_status CHECK (status IN ('Pending','In Progress','Completed','Accepted'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE milestones DROP CONSTRAINT IF EXISTS check_milestones_status');
            DB::statement("ALTER TABLE milestones ADD CONSTRAINT check_milestones_status CHECK (status IN ('Pending','In Progress','Completed'))");
        }

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn(['acceptance_criteria', 'accepted_at', 'accepted_by_client', 'sequence_number']);
        });
    }
};
