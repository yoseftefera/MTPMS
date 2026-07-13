<?php

namespace App\Http\Middleware;

use App\Jobs\WriteAuditLogJob;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditTrailMiddleware
{
    /**
     * Capture request metadata for audit logging on state-changing requests.
     * Attaches a unique X-Request-ID to every response.
     *
     * Audit events are dispatched for POST / PUT / PATCH / DELETE requests,
     * excluding the health-check endpoint and authentication routes.
     *
     * Requirements: 17.1, 17.2, 17.3, 17.4, 17.5, 17.9
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate or propagate a unique request ID
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();
        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);

        // Attach request ID to response for traceability
        $response->headers->set('X-Request-ID', $requestId);

        // Only log state-changing HTTP methods
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        // Skip health check and authentication routes
        $path = $request->path();
        if ($path === 'api/health'
            || str_starts_with($path, 'api/v1/auth/')
        ) {
            return $response;
        }

        // Resolve tenant ID safely (middleware may run before tenant is set)
        $tenantId = null;
        try {
            $tenantId = app('tenant')?->id;
        } catch (\Throwable) {
            // No tenant bound — leave null
        }

        // Resolve authenticated user info
        $userId   = Auth::guard('api')->id();
        $userRole = null;
        try {
            $userRole = Auth::guard('api')->user()?->getRoleNames()?->first();
        } catch (\Throwable) {
            // User not authenticated or roles unavailable
        }

        // Build a dot-notation action type from the HTTP method and path
        // e.g. POST /api/v1/purchase-requests → http.post.purchase-requests
        $cleanPath  = parse_url($request->path(), PHP_URL_PATH) ?? $request->path();
        $stripped   = str_replace(['/api/v1/', '/api/'], '', $cleanPath);
        $trimmed    = trim($stripped, '/');
        $dotPath    = str_replace('/', '.', $trimmed);
        $actionType = 'http.' . strtolower($request->method()) . '.' . $dotPath;

        // Derive the entity type from the first path segment after the prefix
        $strippedForEntity = ltrim(str_replace('/api/v1/', '', '/' . $cleanPath), '/');
        $entityType        = explode('/', $strippedForEntity)[0] ?? 'unknown';

        WriteAuditLogJob::dispatch(
            tenantId:   $tenantId,
            userId:     $userId,
            userRole:   $userRole,
            actionType: $actionType,
            entityType: $entityType,
            entityId:   null,
            before:     null,
            after:      null,
            ipAddress:  $request->ip(),
            requestId:  $requestId,
        );

        return $response;
    }
}
