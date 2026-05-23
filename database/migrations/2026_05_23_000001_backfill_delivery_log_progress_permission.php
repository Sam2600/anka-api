<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill `log_progress` + `track_time` on every existing Delivery role row.
 *
 * Why: Phase 1 RBAC tightening gates /phase-assignments/{id}/progress-logs
 * and /time-entries with these keys. The TenantAppRoleSeeder default for new
 * tenants was updated to include them, but the seeder is idempotent and skips
 * existing role rows — so already-provisioned tenants would lose access at
 * the moment the route middleware lands. This migration closes that gap.
 *
 * Idempotent: only inserts the (role, key) pair when missing.
 */
return new class extends Migration {
    public function up(): void
    {
        $deliveryRoles = DB::table('tenant_app_roles')
            ->where('name', 'Delivery')
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach (['log_progress', 'track_time'] as $key) {
            foreach ($deliveryRoles as $roleId) {
                $exists = DB::table('tenant_app_role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_key', $key)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('tenant_app_role_permissions')->insert([
                    'id'             => (string) Str::uuid(),
                    'role_id'        => $roleId,
                    'permission_key' => $key,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $deliveryRoleIds = DB::table('tenant_app_roles')
            ->where('name', 'Delivery')
            ->pluck('id');

        DB::table('tenant_app_role_permissions')
            ->whereIn('role_id', $deliveryRoleIds)
            ->whereIn('permission_key', ['log_progress', 'track_time'])
            ->delete();
    }
};
