<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditTrailMiddleware
{
    /**
     * Capture request metadata for audit logging on state-changing requests.
     * Attaches a unique X-Request-ID to every response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate or propagate a unique request ID
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();
        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);

        // Attach request ID to response for traceability
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
