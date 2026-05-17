<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC permission check on a per-route basis.
 *
 * Used as `permission:<key>` in `routes/api.php` —
 * e.g. `Route::middleware('permission:manage_crm')`.
 *
 * Roles + permissions live in `tenant_app_roles` /
 * `tenant_app_role_permissions` and are managed by tenant admins via
 * `/api/tenant/app-roles`. The set of permission *strings* is code-defined
 * in `App\Support\PermissionCatalog`.
 *
 * Super admins bypass entirely (consistent with `TenantScope`).
 *
 * Per-request cache: a user's permission list is fetched once per request
 * and stashed on the user object so subsequent middleware hops on the same
 * request don't re-query.
 */
class CheckPermission
{
    /**
     * Per-process cache of resolved permission lists, keyed by user id.
     * Stored statically (not on the user instance) so it can't be persisted
     * by Eloquent — `$model->foo = $bar` would otherwise add `foo` to the
     * model's attributes array and try to write it to the database.
     *
     * @var array<string, array<int, string>>
     */
    private static array $cache = [];

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $allowed = self::permissionsFor($user);

        if (in_array('all', $allowed, true) || in_array($permission, $allowed, true)) {
            return $next($request);
        }

        $roleName = $user->app_role ?? 'unknown';
        return response()->json([
            'message' => "Your role ({$roleName}) does not have permission to perform this action.",
        ], 403);
    }

    /**
     * Effective permission keys for a user, cached on the in-memory user
     * instance so multiple middleware hops on the same request don't requery.
     * Public so AuthUserResource can reuse the same lookup for /auth/me.
     *
     * Bypasses the tenant global scope because the lookup specifies
     * (tenant_id, name) explicitly and runs from both tenant-scoped and
     * auth-only middleware groups.
     *
     * @return array<int, string>
     */
    public static function permissionsFor($user): array
    {
        if (! $user) {
            return [];
        }

        $userId   = $user->id ?? null;
        $roleName = $user->app_role ?? null;
        $tenantId = $user->tenant_id ?? null;

        if (! $userId) {
            // Anonymous / unsaved user — skip the cache.
            return self::lookup($tenantId, $roleName);
        }

        // Cache key includes the role name so an in-request role change
        // (e.g. an admin edits their own role) still recomputes correctly.
        $cacheKey = "{$userId}|{$tenantId}|{$roleName}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        return self::$cache[$cacheKey] = self::lookup($tenantId, $roleName);
    }

    private static function lookup(?string $tenantId, ?string $roleName): array
    {
        if (! $tenantId || ! $roleName) {
            return [];
        }
        return DB::table('tenant_app_role_permissions as p')
            ->join('tenant_app_roles as r', 'r.id', '=', 'p.role_id')
            ->whereNull('r.deleted_at')
            ->where('r.tenant_id', $tenantId)
            ->where('r.name', $roleName)
            ->pluck('p.permission_key')
            ->all();
    }

    /**
     * Clear cached permissions — call from tests or after admin writes
     * if you need the very next call to re-query.
     */
    public static function flushCache(?string $userId = null): void
    {
        if ($userId === null) {
            self::$cache = [];
            return;
        }
        foreach (array_keys(self::$cache) as $k) {
            if (str_starts_with($k, "{$userId}|")) {
                unset(self::$cache[$k]);
            }
        }
    }
}
