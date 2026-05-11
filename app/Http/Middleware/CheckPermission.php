<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC permission check on a per-route basis.
 *
 * Mirrors the frontend `lib/rbac.ts` permission map so backend authorisation
 * lines up with the buttons the user sees. Used as `permission:<key>` in
 * `routes/api.php` — e.g. `Route::middleware('permission:manage_crm')`.
 *
 * The frontend already wraps destructive controls with `<PermissionGuard>`,
 * but a guarded button is only a UX hint: any authenticated tenant user can
 * call the API directly with curl. This middleware closes that gap so the
 * server enforces the same rules the UI advertises.
 *
 * Super admins bypass entirely (consistent with `TenantScope`).
 */
class CheckPermission
{
    /**
     * Permission map. Keep this in sync with `anka-frontend/lib/rbac.ts`.
     * `Admin` always passes via the `all` shortcut so we don't have to list
     * every permission against the Admin role.
     */
    private const ROLE_PERMISSIONS = [
        'Admin'     => ['all'],
        'Executive' => ['view_dashboard', 'view_reports', 'manage_tenant', 'view_projects', 'view_crm'],
        'Sales'     => ['view_crm', 'manage_crm', 'manage_estimation', 'view_contracts'],
        'Delivery'  => ['view_projects', 'manage_projects', 'track_time'],
        'HR'        => ['manage_organization', 'view_employees', 'manage_employees'],
    ];

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Super admins operate globally and bypass app-role permission checks.
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $role = $user->app_role ?? null;
        $allowed = self::ROLE_PERMISSIONS[$role] ?? [];

        if (in_array('all', $allowed, true) || in_array($permission, $allowed, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => "Your role ({$role}) does not have permission to perform this action.",
        ], 403);
    }
}
