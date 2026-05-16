<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_ghost_roles', function (Blueprint $table) {
            $table->uuid('rank_id')->nullable()->after('role_type');
            $table->foreign('rank_id')->references('id')->on('ranks')->onDelete('set null');
            $table->index(['deal_id', 'rank_id'], 'idx_deal_ghost_roles_deal_rank');
        });
    }

    public function down(): void
    {
        Schema::table('deal_ghost_roles', function (Blueprint $table) {
            $table->dropForeign(['rank_id']);
            $table->dropIndex('idx_deal_ghost_roles_deal_rank');
            $table->dropColumn('rank_id');
        });
    }
};
