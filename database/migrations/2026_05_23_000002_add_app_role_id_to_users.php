<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Adds users.app_role_id as a resilient FK to tenant_app_roles.id.
 *
 * Why: today users.app_role is a free-text string and the permission lookup
 * joins on (tenant_id, name). If the rename-cascade in TenantAppRoleController
 * ever misses a row, the user falls through to an empty permission list and
 * gets locked out. Worse, deleting a role (which the controller refuses if
 * users are assigned, but a manual DB write could bypass) silently orphans
 * users with the same result.
 *
 * The string column is intentionally kept (per CLAUDE.md "never rename
 * existing database columns") for API response back-compat. CheckPermission
 * is updated to prefer the FK when set, falling back to the name join for
 * any user the backfill couldn't resolve.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable so existing rows can be backfilled before the FK is enforced.
            // The migration's backfill step below populates the column; orphan rows
            // (app_role with no matching tenant_app_roles entry) stay null and fall
            // back to the legacy name-based lookup at request time.
            $table->uuid('app_role_id')->nullable()->after('app_role');
            $table->foreign('app_role_id')
                ->references('id')->on('tenant_app_roles')
                ->nullOnDelete(); // role deletion null-outs the FK; controller still refuses delete-with-assignees
            $table->index('app_role_id', 'idx_users_app_role_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['app_role_id']);
            $table->dropIndex('idx_users_app_role_id');
            $table->dropColumn('app_role_id');
        });
    }

    /**
     * Resolve (tenant_id, app_role) → tenant_app_roles.id for every user.
     * Orphans (no matching role row) stay null and surface a warning log.
     */
    private function backfill(): void
    {
        $users = DB::table('users')
            ->whereNotNull('tenant_id')
            ->whereNotNull('app_role')
            ->get(['id', 'tenant_id', 'app_role']);

        $orphans = 0;
        foreach ($users as $u) {
            $roleId = DB::table('tenant_app_roles')
                ->where('tenant_id', $u->tenant_id)
                ->where('name', $u->app_role)
                ->whereNull('deleted_at')
                ->value('id');

            if ($roleId === null) {
                $orphans++;
                Log::warning("app_role_id backfill: user {$u->id} has app_role '{$u->app_role}' with no matching tenant_app_roles row in tenant {$u->tenant_id} — leaving app_role_id null");
                continue;
            }

            DB::table('users')->where('id', $u->id)->update(['app_role_id' => $roleId]);
        }

        if ($orphans > 0) {
            Log::warning("app_role_id backfill complete: {$orphans} orphan user(s) — these will fall back to the legacy name-based permission lookup");
        }
    }
};
