<?php

namespace Database\Seeders;

use App\Support\TenantAppRoleSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aligns existing tenants + users with the Phase 1–6 RBAC changes.
 *
 * Why this exists separately from TenantAppRoleSeeder:
 *   The original TenantAppRoleSeeder is "add a role if its name is missing"
 *   and stops there. That leaves two upgrade gaps once new permission keys
 *   land in PermissionCatalog or the seeder's defaults() map:
 *     1) A role row that already exists never picks up newly-defined default
 *        keys (e.g. `approve_time`, `log_progress`).
 *     2) Users created before Phase 5's migration have a null `app_role_id`
 *        and fall through to the legacy name-based permission lookup.
 *
 * This seeder is fully idempotent and ADDITIVE ONLY:
 *   - Inserts missing default role rows (same as TenantAppRoleSeeder)
 *   - Inserts missing (role, permission) pairs into already-seeded roles
 *   - Never removes a permission a tenant added themselves
 *   - Never renames or deletes a role
 *   - Populates users.app_role_id where currently null; never overwrites
 *
 * No tenant, employee, deal, project, or other business data is touched —
 * only `tenant_app_roles`, `tenant_app_role_permissions`, and the
 * `users.app_role_id` column.
 *
 * Run via:
 *   php artisan db:seed --class=Database\\Seeders\\RbacAlignmentSeeder
 */
class RbacAlignmentSeeder extends Seeder
{
    public function run(): void
    {
        $tenantIds = DB::table('tenants')->pluck('id');
        $rolesAdded = 0;
        $permsAdded = 0;
        $usersBackfilled = 0;
        $orphanUsers = 0;

        foreach ($tenantIds as $tenantId) {
            // Step 1 — ensure every default role row exists for this tenant.
            // TenantAppRoleSeeder::seed() handles the "create role if missing"
            // case idempotently. We re-use it so the single source of truth
            // for default roles stays in one place.
            TenantAppRoleSeeder::seed((string) $tenantId);

            // Step 2 — for every default role that already existed (and so was
            // skipped by step 1), add any default permission keys it's missing.
            // This is the upgrade gap closer: new keys in defaults() get
            // applied without removing any tenant-added customizations.
            foreach (TenantAppRoleSeeder::defaults() as $roleName => $defaultPerms) {
                $roleId = DB::table('tenant_app_roles')
                    ->where('tenant_id', $tenantId)
                    ->where('name', $roleName)
                    ->whereNull('deleted_at')
                    ->value('id');
                if (! $roleId) {
                    // Shouldn't happen after step 1, but guard against a
                    // soft-deleted row blocking the upsert.
                    continue;
                }

                $existingPerms = DB::table('tenant_app_role_permissions')
                    ->where('role_id', $roleId)
                    ->pluck('permission_key')
                    ->all();

                $missing = array_diff($defaultPerms, $existingPerms);
                foreach ($missing as $key) {
                    DB::table('tenant_app_role_permissions')->insert([
                        'id'             => (string) Str::uuid(),
                        'role_id'        => $roleId,
                        'permission_key' => $key,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                    $permsAdded++;
                }
            }

            // Step 3 — backfill users.app_role_id for any user in this tenant
            // whose FK is still null. Match by (tenant_id, app_role) → id.
            // Orphans (app_role string with no matching role row) stay null
            // and fall through to the legacy name lookup at request time.
            $usersNeedingBackfill = DB::table('users')
                ->where('tenant_id', $tenantId)
                ->whereNull('app_role_id')
                ->whereNotNull('app_role')
                ->whereNull('deleted_at')
                ->get(['id', 'app_role']);

            foreach ($usersNeedingBackfill as $u) {
                $roleId = DB::table('tenant_app_roles')
                    ->where('tenant_id', $tenantId)
                    ->where('name', $u->app_role)
                    ->whereNull('deleted_at')
                    ->value('id');

                if ($roleId === null) {
                    $orphanUsers++;
                    $this->command?->warn("  · user {$u->id} has orphan app_role '{$u->app_role}' — left null (falls back to name lookup)");
                    continue;
                }

                DB::table('users')->where('id', $u->id)->update(['app_role_id' => $roleId]);
                $usersBackfilled++;
            }
        }

        // Step 4 — bust the in-process permission cache so any test or tinker
        // session running this seeder sees fresh data immediately. Production
        // queue workers will pick up changes on their next request as long as
        // TenantAppRoleController::flushPermissionCacheForRole was wired in
        // Phase 2 (which it is).
        \App\Http\Middleware\CheckPermission::flushCache();

        $this->command?->info("RBAC alignment complete:");
        $this->command?->info("  · tenants processed:     " . count($tenantIds));
        $this->command?->info("  · default perms added:   {$permsAdded}");
        $this->command?->info("  · users backfilled:      {$usersBackfilled}");
        $this->command?->info("  · orphan users:          {$orphanUsers}");
    }
}
