<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Proper FK replacing the free-text manager string.
            // Nullable so departments can be created before any employee exists.
            $table->uuid('manager_id')->nullable()->after('name');
            $table->foreign('manager_id')
                  ->references('id')
                  ->on('employees')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }
};
