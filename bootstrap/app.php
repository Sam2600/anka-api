<?php

use App\Services\AuditService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\LogSystemErrors::class);
        $middleware->alias([
            'tenant'      => \App\Http\Middleware\TenantScope::class,
            'super_admin' => \App\Http\Middleware\SuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (\Throwable $e, \Illuminate\Http\Request $request) {
            $tenantId = $request->header('X-Tenant-ID');
            $path = $request->path();
            $message = $e->getMessage();
            $class = get_class($e);

            AuditService::logError(
                'system.exception',
                "{$class} on {$path}: {$message}",
                $tenantId ?: null,
            );
        });

        // Return JSON for all API / JSON-expecting requests so the frontend
        // never receives an HTML error page it cannot parse.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->expectsJson()) {
                return null; // let Laravel render HTML for web routes
            }

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(['message' => 'Not found'], 404);
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors'  => $e->errors(),
                ], 422);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                return response()->json(
                    ['message' => $e->getMessage() ?: 'HTTP error'],
                    $e->getStatusCode()
                );
            }

            return response()->json(['message' => 'Server error'], 500);
        });
    })->create();
