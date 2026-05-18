<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the default app roles + permissions for a tenant.
 *
 * Invoked from:
 *   - Tenant model `created` event (so every new tenant gets defaults)
 *   - The original migration `2026_05_17_000001_create_tenant_app_roles_tables`
 *     (backfill for tenants that already existed when RBAC shipped)
 *
 * The default set mirrors the hardcoded ROLE_PERMISSIONS map that lived in
 * CheckPermission before RBAC went database-driven, so behaviour out of the
 * box is identical to the pre-RBAC era.
 *
 * Idempotent: skips any role name that already exists for the tenant. Safe
 * to re-run on a partially-seeded tenant.
 */
class TenantAppRoleSeeder
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function defaults(): array
    {
        return [
            'Admin'     => ['all'],
            'Executive' => ['view_dashboard', 'view_reports', 'manage_tenant', 'view_projects', 'view_schedule_tracking', 'view_crm'],
            // Schedule Tracking added to Sales + HR too so every employee
            // account can demo the page; tighten back to Delivery-only if
            // production wants stricter role separation.
            'Sales'     => ['view_crm', 'manage_crm', 'manage_estimation', 'view_contracts', 'view_schedule_tracking'],
            // Delivery is the IC employee surface: only My Schedule (public)
            // and the schedule-tracking dashboards. They log progress through
            // My Schedule's "Log Progress" modal which uses the phase-assignment
            // progress-log endpoint — no `track_time` permission needed for that.
            'Delivery'  => ['view_schedule_tracking'],
            'HR'        => ['manage_organization', 'view_employees', 'manage_employees', 'view_schedule_tracking'],
        ];
    }

    public static function seed(string $tenantId): void
    {
        $existing = DB::table('tenant_app_roles')
            ->where('tenant_id', $tenantId)
            ->pluck('name')
            ->all();

        foreach (self::defaults() as $name => $perms) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            $roleId = (string) Str::uuid();
            DB::table('tenant_app_roles')->insert([
                'id'         => $roleId,
                'tenant_id'  => $tenantId,
                'name'       => $name,
                'is_system'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($perms as $perm) {
                DB::table('tenant_app_role_permissions')->insert([
                    'id'             => (string) Str::uuid(),
                    'role_id'        => $roleId,
                    'permission_key' => $perm,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }
}
