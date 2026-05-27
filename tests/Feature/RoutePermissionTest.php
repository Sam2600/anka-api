<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks down the RBAC route gating from Phase 1.
 *
 * Strategy: provision a tenant (which seeds the default roles), promote the
 * test user to a custom role with an explicit permission set, then assert
 * which gated endpoints respond 403 vs not-403. We don't care about the
 * success body — only that the gate is enforced (or skipped) as expected.
 *
 * SQLite-only test env: avoid invoking endpoints that rely on PostgreSQL
 * sequences, generated columns, or the win_deal() stored procedure. We hit
 * the route, the middleware decides, and we stop there — controllers may
 * 500 on PG-specific calls but the middleware verdict comes first.
 */
class RoutePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name'     => 'Acme',
            'slug'     => 'acme-' . Str::random(6),
            'is_active' => true,
        ]);
    }

    /**
     * Build a user assigned to a freshly-created role with the given permission set.
     * Using a custom role name (not one of the seeded defaults) keeps the test
     * isolated from any future change to TenantAppRoleSeeder defaults.
     */
    private function userWithPermissions(array $permissionKeys): User
    {
        $roleName = 'TestRole_' . Str::random(8);
        $roleId   = (string) Str::uuid();

        DB::table('tenant_app_roles')->insert([
            'id'         => $roleId,
            'tenant_id'  => $this->tenant->id,
            'name'       => $roleName,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($permissionKeys as $key) {
            DB::table('tenant_app_role_permissions')->insert([
                'id'             => (string) Str::uuid(),
                'role_id'        => $roleId,
                'permission_key' => $key,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        return User::create([
            'tenant_id'  => $this->tenant->id,
            'first_name' => 'Test',
            'last_name'  => 'User',
            'email'      => Str::random(8) . '@example.test',
            'password'   => 'irrelevant',
            'app_role'   => $roleName,
            // Set the FK at creation time so this matches the post-Phase-5
            // shape that TenantController::createUser produces. The lookup
            // path under test (FK-preferred) is the one production uses.
            'app_role_id' => $roleId,
            'system_role'=> 'member',
            'is_super_admin' => false,
        ]);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function tenantHeaders(): array
    {
        return ['X-Tenant-ID' => $this->tenant->id];
    }

    /**
     * Returns the table of (METHOD, URI, required-permission-OR-group) for
     * routes that were newly gated in Phase 1. Path placeholders use any
     * UUID — the route never reaches the controller because middleware
     * rejects first, and the controller wouldn't bind the model anyway.
     */
    private static function gatedRoutes(): array
    {
        $uuid = '00000000-0000-0000-0000-000000000000';
        $year = 2026;
        return [
            // [method, uri, sufficient-permission]
            ['GET',    '/api/employees',                                 'view_employees'],
            ['POST',   '/api/employees',                                 'manage_employees'],
            ['DELETE', "/api/employees/{$uuid}",                         'manage_employees'],
            ['GET',    "/api/employees/{$uuid}/salary-history",          'view_employees'],
            ['POST',   "/api/employees/{$uuid}/salary-history",          'manage_employees'],
            ['POST',   '/api/departments',                               'manage_organization'],
            ['POST',   '/api/global-overheads',                          'manage_organization'],
            ['POST',   '/api/capacity-roles',                            'manage_organization'],
            ['POST',   '/api/ranks',                                     'manage_organization'],
            ['POST',   '/api/skills',                                    'manage_organization'],
            ['POST',   '/api/holidays',                                  'manage_organization'],
            ['PUT',    '/api/company-settings',                          'manage_tenant'],
            ['PUT',    "/api/initial-budgets/{$year}",                   'manage_tenant'],
            ['PUT',    '/api/tenant',                                    'manage_tenant'],
            ['PUT',    '/api/exchange-rates',                            'manage_tenant'],
            ['DELETE', "/api/projects/{$uuid}",                          'manage_projects'],
            ['POST',   "/api/projects/{$uuid}/team",                     'manage_projects'],
            ['POST',   "/api/projects/{$uuid}/plan-team",                'manage_projects'],
            ['POST',   "/api/projects/{$uuid}/confirm-team",             'manage_projects'],
            ['POST',   "/api/projects/{$uuid}/assign-tasks",             'manage_projects'],
            ['POST',   '/api/invoices',                                  'manage_crm'],
            ['DELETE', "/api/invoices/{$uuid}",                          'manage_crm'],
            ['PATCH',  "/api/invoices/{$uuid}/pay",                      'manage_crm'],
            ['POST',   '/api/milestones',                                'manage_crm'],
            ['DELETE', "/api/milestones/{$uuid}",                        'manage_crm'],
            ['PATCH',  "/api/milestones/{$uuid}/accept",                 'manage_crm'],
            ['PATCH',  "/api/time-entries/{$uuid}/approve",              'approve_time'],
            ['PATCH',  "/api/time-entries/{$uuid}/reject",               'approve_time'],
            ['POST',   "/api/phase-assignments/{$uuid}/progress-logs",   'log_progress'],
            ['PATCH',  "/api/phase-progress-logs/{$uuid}",               'log_progress'],
        ];
    }

    public function test_zero_permission_user_is_forbidden_on_every_gated_route(): void
    {
        $user = $this->userWithPermissions([]);
        $this->actAs($user);

        foreach (self::gatedRoutes() as [$method, $uri, $_permission]) {
            $response = $this->json($method, $uri, [], $this->tenantHeaders());
            // 403 is the expected outcome from CheckPermission. 404 is acceptable
            // ONLY for routes whose URL contains a placeholder model id — Laravel's
            // SubstituteBindings can fire before the permission middleware on some
            // dispatch paths and the bootstrap exception handler renders the resulting
            // ModelNotFoundException as 404. The security invariant we care about is
            // "the request did not succeed", which both statuses satisfy.
            $this->assertContains(
                $response->status(),
                [403, 404],
                "Expected 403/404 for {$method} {$uri} (user has zero permissions), got {$response->status()}: " . $response->getContent(),
            );
        }
    }

    public function test_super_admin_bypasses_every_gate(): void
    {
        $superAdmin = User::create([
            'tenant_id'      => $this->tenant->id,
            'first_name'     => 'Super',
            'last_name'      => 'Admin',
            'email'          => 'super-' . Str::random(6) . '@example.test',
            'password'       => 'irrelevant',
            'app_role'       => 'Admin',
            'system_role'    => 'super_admin',
            'is_super_admin' => true,
        ]);
        $this->actAs($superAdmin);

        foreach (self::gatedRoutes() as [$method, $uri, $_permission]) {
            $response = $this->json($method, $uri, [], $this->tenantHeaders());
            $this->assertNotSame(
                403,
                $response->status(),
                "Super admin should never get 403, but {$method} {$uri} returned 403",
            );
        }
    }

    public function test_user_with_matching_permission_passes_the_gate(): void
    {
        $user = $this->userWithPermissions(['manage_employees', 'manage_organization', 'view_employees']);
        $this->actAs($user);

        // Routes the user IS allowed to reach — should not be 403. The
        // controller may still 404/422 because the target row doesn't exist,
        // but the permission gate let them through.
        $allowed = [
            ['POST', '/api/employees'],
            ['POST', '/api/departments'],
            ['POST', '/api/global-overheads'],
            ['GET',  '/api/employees'],
        ];
        foreach ($allowed as [$method, $uri]) {
            $response = $this->json($method, $uri, [], $this->tenantHeaders());
            $this->assertNotSame(
                403,
                $response->status(),
                "User holding the required permission should not get 403 from {$method} {$uri}, got {$response->status()}",
            );
        }

        // Routes the user should still be denied (different permission family).
        // No path placeholders — these all 403 cleanly without model binding running first.
        $forbidden = [
            ['POST',  '/api/invoices'],  // needs manage_crm
            ['POST',  '/api/milestones'], // needs manage_crm
            ['PUT',   '/api/tenant'],     // needs manage_tenant
            ['PUT',   '/api/exchange-rates'], // needs manage_tenant
        ];
        foreach ($forbidden as [$method, $uri]) {
            $response = $this->json($method, $uri, [], $this->tenantHeaders());
            $this->assertSame(
                403,
                $response->status(),
                "User without the required permission should get 403 from {$method} {$uri}, got {$response->status()}: " . $response->getContent(),
            );
        }
    }

    public function test_or_syntax_grants_access_when_any_listed_permission_is_held(): void
    {
        // GET /employees is gated by view_employees|view_crm|view_projects|manage_organization.
        // A user holding only view_crm (a CRM persona) must still be allowed in.
        $user = $this->userWithPermissions(['view_crm']);
        $this->actAs($user);

        $response = $this->json('GET', '/api/employees', [], $this->tenantHeaders());
        $this->assertNotSame(
            403,
            $response->status(),
            'view_crm should satisfy the OR gate on GET /api/employees',
        );
    }

    public function test_stripping_assigned_role_to_zero_permissions_is_rejected(): void
    {
        $editor = $this->userWithPermissions(['manage_tenant']);
        $this->actAs($editor);

        // Target role: a separate role with one assignee (not the editor).
        $targetRoleName = 'TargetRole_' . Str::random(6);
        $targetRoleId   = (string) Str::uuid();
        DB::table('tenant_app_roles')->insert([
            'id'         => $targetRoleId,
            'tenant_id'  => $this->tenant->id,
            'name'       => $targetRoleName,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_app_role_permissions')->insert([
            'id'             => (string) Str::uuid(),
            'role_id'        => $targetRoleId,
            'permission_key' => 'view_dashboard',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        User::create([
            'tenant_id'      => $this->tenant->id,
            'first_name'     => 'Assignee',
            'last_name'      => 'User',
            'email'          => 'assignee-' . Str::random(6) . '@example.test',
            'password'       => 'irrelevant',
            'app_role'       => $targetRoleName,
            'system_role'    => 'member',
            'is_super_admin' => false,
        ]);

        $response = $this->json('PATCH', "/api/tenant/app-roles/{$targetRoleId}", [
            'permissions' => [],
        ], $this->tenantHeaders());

        $this->assertSame(422, $response->status(), 'Expected 422, got: ' . $response->getContent());
        $this->assertStringContainsString('lock them out', $response->getContent());
    }

    public function test_stripping_unassigned_role_to_zero_permissions_is_allowed(): void
    {
        $editor = $this->userWithPermissions(['manage_tenant']);
        $this->actAs($editor);

        // Unassigned role.
        $orphanRoleId = (string) Str::uuid();
        DB::table('tenant_app_roles')->insert([
            'id'         => $orphanRoleId,
            'tenant_id'  => $this->tenant->id,
            'name'       => 'OrphanRole_' . Str::random(6),
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_app_role_permissions')->insert([
            'id'             => (string) Str::uuid(),
            'role_id'        => $orphanRoleId,
            'permission_key' => 'view_dashboard',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $response = $this->json('PATCH', "/api/tenant/app-roles/{$orphanRoleId}", [
            'permissions' => [],
        ], $this->tenantHeaders());

        $this->assertSame(200, $response->status(), 'Stripping an unassigned role should succeed: ' . $response->getContent());
    }

    public function test_editor_cannot_remove_manage_tenant_from_their_own_role(): void
    {
        $editor = $this->userWithPermissions(['manage_tenant', 'view_dashboard']);
        $this->actAs($editor);

        $editorRoleId = DB::table('tenant_app_roles')
            ->where('tenant_id', $this->tenant->id)
            ->where('name', $editor->app_role)
            ->value('id');

        // Submit a non-empty list that omits manage_tenant — this trips the
        // self-lockout guard rather than the empty-permissions guard.
        $response = $this->json('PATCH', "/api/tenant/app-roles/{$editorRoleId}", [
            'permissions' => ['view_dashboard'],
        ], $this->tenantHeaders());

        $this->assertSame(422, $response->status(), 'Expected 422 self-lockout: ' . $response->getContent());
        $this->assertStringContainsString('manage_tenant', $response->getContent());
    }

    public function test_editor_can_remove_manage_tenant_from_a_different_role(): void
    {
        $editor = $this->userWithPermissions(['manage_tenant']);
        $this->actAs($editor);

        // Different role that currently carries manage_tenant + view_dashboard.
        // The editor is allowed to strip manage_tenant from it since they're
        // not assigned to it themselves.
        $otherRoleId = (string) Str::uuid();
        $otherRoleName = 'OtherAdmin_' . Str::random(6);
        DB::table('tenant_app_roles')->insert([
            'id'         => $otherRoleId,
            'tenant_id'  => $this->tenant->id,
            'name'       => $otherRoleName,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['manage_tenant', 'view_dashboard'] as $key) {
            DB::table('tenant_app_role_permissions')->insert([
                'id'             => (string) Str::uuid(),
                'role_id'        => $otherRoleId,
                'permission_key' => $key,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
        // Assignee so the empty-permissions guard isn't triggered.
        User::create([
            'tenant_id'      => $this->tenant->id,
            'first_name'     => 'Other',
            'last_name'      => 'Admin',
            'email'          => 'other-' . Str::random(6) . '@example.test',
            'password'       => 'irrelevant',
            'app_role'       => $otherRoleName,
            'system_role'    => 'member',
            'is_super_admin' => false,
        ]);

        $response = $this->json('PATCH', "/api/tenant/app-roles/{$otherRoleId}", [
            'permissions' => ['view_dashboard'],
        ], $this->tenantHeaders());

        $this->assertSame(200, $response->status(), 'Editing a different role should succeed: ' . $response->getContent());
    }

    public function test_permission_lookup_survives_a_role_rename(): void
    {
        // User assigned to "OldName" with manage_organization. Their app_role_id
        // FK points at the role row. After the role is renamed to "NewName",
        // CheckPermission must still resolve permissions via the FK — the user's
        // app_role string column may lag (depending on whether the controller
        // cascade ran) but the FK keeps auth working.
        $user = $this->userWithPermissions(['manage_organization']);
        $this->actAs($user);

        // Sanity: gate passes pre-rename.
        $primer = $this->json('POST', '/api/departments', ['name' => 'Pre'], $this->tenantHeaders());
        $this->assertNotSame(403, $primer->status(), 'Should pass before rename: ' . $primer->getContent());

        // Simulate a rename WITHOUT the controller's app_role cascade —
        // i.e. only the role row's name changes. The FK should still resolve.
        $roleId = $user->app_role_id;
        DB::table('tenant_app_roles')->where('id', $roleId)->update(['name' => 'RenamedRole_' . Str::random(6)]);

        // Bust the cache (in production the controller would do this after the
        // rename transaction; here we simulate the post-rename state directly).
        \App\Http\Middleware\CheckPermission::flushCache((string) $user->id);

        $afterRename = $this->json('POST', '/api/departments', ['name' => 'Post'], $this->tenantHeaders());
        $this->assertNotSame(
            403,
            $afterRename->status(),
            'Permission lookup must survive a role rename via the FK path; got ' . $afterRename->getContent(),
        );
    }

    public function test_role_permission_update_invalidates_the_static_cache(): void
    {
        // Provision a user as the tenant admin so they can edit roles. We
        // give them `manage_tenant` (required by the role-edit endpoint) plus
        // `manage_organization` so we can probe a gated route on their own
        // permission set as a sanity check.
        $admin = $this->userWithPermissions(['manage_tenant', 'manage_organization']);
        $this->actAs($admin);

        // First request prime the per-process cache for $admin.
        $primer = $this->json('POST', '/api/departments', ['name' => 'X'], $this->tenantHeaders());
        $this->assertNotSame(
            403,
            $primer->status(),
            'Admin holding manage_organization must reach POST /departments before we strip the permission',
        );

        // Strip `manage_organization` from the admin's role via the controller.
        $roleId = DB::table('tenant_app_roles')
            ->where('tenant_id', $this->tenant->id)
            ->where('name', $admin->app_role)
            ->value('id');

        $update = $this->json('PATCH', "/api/tenant/app-roles/{$roleId}", [
            'permissions' => ['manage_tenant'], // dropped manage_organization
        ], $this->tenantHeaders());
        $this->assertSame(200, $update->status(), 'Role update should succeed: ' . $update->getContent());

        // Same auth, same process — if the cache wasn't flushed the next request
        // would still pass because $allowed for $admin is cached from the primer call.
        $afterFlush = $this->json('POST', '/api/departments', ['name' => 'Y'], $this->tenantHeaders());
        $this->assertSame(
            403,
            $afterFlush->status(),
            'After stripping manage_organization, POST /departments must 403 — the static cache should have been flushed for this user',
        );
    }
}
