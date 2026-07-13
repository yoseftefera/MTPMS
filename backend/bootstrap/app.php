<?php

use App\Http\Middleware\AddRequestIdMiddleware;
use App\Http\Middleware\TenantIdentificationMiddleware;
use App\Http\Middleware\AuditTrailMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/api/health-check',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Global middleware applied to all requests — X-Request-ID must run first
        // so every response (including errors) carries the header.
        $middleware->prepend(AddRequestIdMiddleware::class);

        // Tenant identification runs after the request ID is established
        $middleware->append(TenantIdentificationMiddleware::class);

        // API middleware group
        $middleware->api(append: [
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);

        // Named middleware aliases
        $middleware->alias([
            // Spatie built-in aliases (role-name checks)
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            // Custom RoleMiddleware wraps Spatie's PermissionMiddleware with:
            //   - Standard JSON 403 envelope on failure
            //   - Async audit log entry on access denial
            // Use 'role.check:permission.name' on protected routes.
            'role.check'         => \App\Http\Middleware\RoleMiddleware::class,
            // Spatie's PermissionMiddleware (direct, no custom envelope)
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'auth.jwt'           => \App\Http\Middleware\AuthMiddleware::class,
            'audit'              => AuditTrailMiddleware::class,
            'throttle.auth'      => \Illuminate\Routing\Middleware\ThrottleRequests::class.':auth',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return app(\App\Exceptions\Handler::class)->renderApiException($e, $request);
            }
        });
    })
    ->create();
