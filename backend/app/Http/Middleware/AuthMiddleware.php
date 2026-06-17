<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthMiddleware
{
    /**
     * Redis key prefix for blacklisted JTIs (must match AuthService::BLACKLIST_PREFIX).
     */
    private const BLACKLIST_PREFIX = 'jwt:blacklist:';

    /**
     * Validate the JWT token and authenticate the user.
     *
     * Checks:
     *  1. Token is present, parseable, and not expired
     *  2. Token jti is not in the Redis blacklist (i.e. not logged out)
     *  3. Authenticated user exists
     *  4. JWT tenant_id matches the resolved tenant context
     *  5. JWT iat is within the tenant-configured session timeout window
     *
     * Rejects requests with missing, expired, blacklisted, or invalid tokens with HTTP 401.
     *
     * Requirements: 2.1, 2.4, 2.9
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $tokenParser = JWTAuth::parseToken();
            $payload     = $tokenParser->getPayload();

            // Check Redis blacklist — token was explicitly logged out
            $jti = $payload->get('jti');
            if ($jti && Redis::exists(self::BLACKLIST_PREFIX . $jti)) {
                return $this->unauthorizedResponse('Token has been revoked.');
            }

            $user = $tokenParser->authenticate();

            if (! $user) {
                return $this->unauthorizedResponse('User not found.');
            }

            // Enforce tenant consistency — JWT tenant_id must match resolved tenant
            if (app()->has('tenant')) {
                $tenant      = app('tenant');
                $jwtTenantId = $payload->get('tenant_id');

                if ($jwtTenantId && (string) $tenant->id !== (string) $jwtTenantId) {
                    return $this->unauthorizedResponse('Token tenant mismatch.');
                }

                // Enforce session timeout — reject JWTs older than tenant-configured timeout
                // Default: 1440 minutes (24 hours)
                $timeoutMinutes = (int) ($tenant->settings['session_timeout_minutes'] ?? 1440);
                $iat            = $payload->get('iat');

                if ($iat && (now()->timestamp - $iat) > ($timeoutMinutes * 60)) {
                    return $this->unauthorizedResponse('Session has expired. Please log in again.');
                }
            }

        } catch (TokenExpiredException) {
            return $this->unauthorizedResponse('Token has expired.');
        } catch (TokenInvalidException) {
            return $this->unauthorizedResponse('Token is invalid.');
        } catch (JWTException) {
            return $this->unauthorizedResponse('Token not provided.');
        }

        return $next($request);
    }

    private function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
            'meta'    => null,
        ], 401);
    }
}
