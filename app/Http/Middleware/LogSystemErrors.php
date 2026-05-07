<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogSystemErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            $tenantId = $request->header('X-Tenant-ID');
            $path = $request->path();
            $method = $request->method();
            $status = $response->getStatusCode();

            AuditService::logError(
                'system.error',
                "{$method} /{$path} returned {$status}",
                $tenantId ?: null,
            );
        }

        return $response;
    }
}
