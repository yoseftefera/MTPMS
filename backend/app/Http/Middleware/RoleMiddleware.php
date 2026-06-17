<?php

namespace App\Http\Middleware;

use App\Jobs\WriteAuditLogJob;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoleMiddleware — wraps Spatie's PermissionMiddleware to enforce permission
 * checks on every protected route and return the platform's standard JSON
 * error envelope with HTTP 403 on failure.
 *
 * Usage in routes:
 *   Route::middleware('role.check:users.view')->group(...)
 *   Route::middleware('permission:users.view')->group(...)
 *
 * On access denial, this middleware:
 *   1. Returns HTTP 403 with the standard JSON envelope.
 *   2. Dispatches an async audit log entry recording the denial.
 *
 * Requirements: 3.2, 3.9
 */
class RoleMiddleware
{
    public function __construct(private readonly PermissionMiddleware $spatieMiddleware)
    {
    }

    /**
     * Check that the authenticated user holds the required permission.
     *
     * Delegates to Spatie's PermissionMiddleware. If the user lacks the
     * permission, Spatie throws UnauthorizedException which is caught here
     * and converted to the standard 403 envelope. The denial is also
     * recorded in the audit log.
     *
     * @param  string  $permission  The permission name (e.g. "users.view")
     * @param  string  $guard       The guard to use (default: "api")
     */
    public function handle(Request $request, Closure $next, string $permission, string $guard = 'api'): Response
    {
        try {
            return $this->spatieMiddleware->handle($request, $next, $permission, $guard);
        } catch (UnauthorizedException) {
            $this->logAccessDenial($request, $permission);
            return $this->forbiddenResponse($permission);
        } catch (\Throwable $e) {
            // Spatie may throw a plain HttpException with 403 status
            if (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 403) {
                $this->logAccessDenial($request, $permission);
                return $this->forbiddenResponse($permission);
            }

            throw $e;
        }
    }

    /**
     * Build the standard 403 JSON envelope.
     */
    private function forbiddenResponse(string $permission): Response
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => 'You do not have permission to perform this action.',
            'errors'  => [
                'permission' => ["Required permission: {$permission}"],
            ],
            'meta'    => null,
        ], 403);
    }

    /**
     * Dispatch an async audit log entry for the access denial.
     *
     * Requirements: 3.9
     */
    private function logAccessDenial(Request $request, string $permission): void
    {
        try {
            $user = Auth::guard('api')->user();

            WriteAuditLogJob::dispatch(
                tenantId:   $user?->tenant_id ?? (app()->has('tenant') ? app('tenant')->id : null),
                userId:     $user?->id,
                userRole:   $user?->getRoleNames()->first(),
                actionType: 'access_denied',
                entityType: 'permission',
                entityId:   null,
                before:     null,
                after:      [
                    'permission'  => $permission,
                    'route'       => $request->path(),
                    'method'      => $request->method(),
                ],
                ipAddress:  $request->ip() ?? '0.0.0.0',
                requestId:  $request->header('X-Request-ID'),
            );
        } catch (\Throwable) {
            // Never let audit log failure break the 403 response
        }
    }
}
