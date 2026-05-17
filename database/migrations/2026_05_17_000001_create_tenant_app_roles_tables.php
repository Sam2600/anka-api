<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant admin-managed RBAC.
 *
 * Replaces the hardcoded ROLE_PERMISSIONS map in CheckPermission middleware
 * with database-driven role definitions. Each tenant owns its own roles and
 * may add, rename, delete, or reassign permissions. The set of permission
 * *strings* itself stays code-defined (see App\Support\PermissionCatalog) —
 * tenants compose, they don't invent.
 *
 * users.app_role keeps the role NAME (not an FK) so the column survives if
 * the underlying role row is renamed/deleted; lookups join on
 * (tenant_id, name). The legacy CHECK constraint on app_role gets dropped
 * so tenants can add roles like "Junior Sales".
 *
 * Defaults seeded for every tenant: Admin, Executive, Sales, Delivery, HR
 * — mirroring today's hardcoded map so behaviour is identical out of the box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_app_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 64);
            $table->string('description', 255)->nullable();
            // is_system roles are the seeded defaults — admin UI prevents
            // delete + rename but allows permission edits.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'name'], 'uniq_tenant_app_roles_tenant_name');
            $table->index('tenant_id', 'idx_tenant_app_roles_tenant');
        });

        Schema::create('tenant_app_role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('role_id');
            // Permission key matches strings in PermissionCatalog (e.g. "view_crm").
            // Stored as text so PermissionCatalog changes don't require an enum migration.
            $table->string('permission_key', 64);
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('tenant_app_roles')->onDelete('cascade');
            $table->unique(['role_id', 'permission_key'], 'uniq_role_perm');
        });

        // Drop the CHECK constraint so admins can introduce custom role names
        // (Postgres only — SQLite test env never had it).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS check_users_app_role');
        }

        // Backfill: for every tenant, create the 5 default roles with the
        // same permissions the old hardcoded map gave them.
        $defaults = [
            'Admin'     => ['all'],
            'Executive' => ['view_dashboard', 'view_reports', 'manage_tenant', 'view_projects', 'view_crm'],
            'Sales'     => ['view_crm', 'manage_crm', 'manage_estimation', 'view_contracts'],
            'Delivery'  => ['view_projects', 'manage_projects', 'track_time'],
            'HR'        => ['manage_organization', 'view_employees', 'manage_employees'],
        ];

        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            foreach ($defaults as $name => $perms) {
                $roleId = (string) \Illuminate\Support\Str::uuid();
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
                        'id'             => (string) \Illuminate\Support\Str::uuid(),
                        'role_id'        => $roleId,
                        'permission_key' => $perm,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_app_role_permissions');
        Schema::dropIfExists('tenant_app_roles');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT check_users_app_role CHECK (app_role IN ('Admin','Executive','Sales','Delivery','HR'))");
        }
    }
};
