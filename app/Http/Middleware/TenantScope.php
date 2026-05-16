<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Tenant;

class TenantScope
{
    public function handle(Request $request, Closure $next): Response
    {
        // Super admins operate globally — no tenant scope needed.
        $user = $request->user();
        if ($user && $user->isSuperAdmin()) {
            return $next($request);
        }

        $tenantId = $request->header('X-Tenant-ID');

        if (!$tenantId) {
            return response()->json(['message' => 'Missing X-Tenant-ID header'], 400);
        }

        // Reject non-UUID values before they reach PostgreSQL (avoids "invalid input syntax for type uuid" errors).
        if (!Str::isUuid($tenantId)) {
            return response()->json(['message' => 'Invalid X-Tenant-ID format — must be a valid UUID'], 400);
        }

        // Verify the tenant actually exists to prevent spoofed tenant IDs.
        if (!Tenant::where('id', $tenantId)->where('is_active', true)->exists()) {
            return response()->json(['message' => 'Invalid or inactive tenant'], 403);
        }

        // Cross-tenant defence: the header is user-supplied, so a Tenant-A
        // user could send Tenant-B's UUID and access B's data unless we
        // verify ownership. Super admins are allowed across tenants and
        // skipped above; regular users must match their own tenant.
        if ((string) $user->tenant_id !== (string) $tenantId) {
            return response()->json(['message' => 'Tenant access denied'], 403);
        }

        app()->instance('tenant_id', $tenantId);

        return $next($request);
    }
}
