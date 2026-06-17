<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Requests\V1\Auth\PasswordResetConfirmRequest;
use App\Http\Requests\V1\Auth\PasswordResetRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * AuthController — handles all /api/v1/auth/* endpoints.
 *
 * Endpoints:
 *   POST   /api/v1/auth/login
 *   POST   /api/v1/auth/logout            (auth.jwt)
 *   POST   /api/v1/auth/refresh           (auth.jwt)
 *   GET    /api/v1/auth/me                (auth.jwt)
 *   POST   /api/v1/auth/password/request
 *   POST   /api/v1/auth/password/reset
 *   GET    /api/v1/auth/csrf-token
 *
 * Requirements: 2.1, 2.5, 2.6, 2.9
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/login
    // -------------------------------------------------------------------------

    /**
     * Authenticate a user and issue a signed JWT.
     *
     * The JWT payload includes: user_id, tenant_id, role, permissions, iat, exp, jti.
     *
     * Requirements: 2.1
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Tenant must already be resolved by TenantIdentificationMiddleware
        $tenant = app('tenant');

        $result = $this->authService->login(
            email: $request->input('email'),
            password: $request->input('password'),
            tenantId: $tenant->id,
            ipAddress: $request->ip(),
            requestId: $request->header('X-Request-ID'),
        );

        if (! $result) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Invalid credentials.',
                'errors'  => ['credentials' => ['The provided credentials are incorrect.']],
                'meta'    => null,
            ], 401);
        }

        $user  = $result['user'];
        $token = $result['token'];

        return response()->json([
            'success' => true,
            'data'    => [
                'access_token' => $token,
                'token_type'   => 'bearer',
                'expires_in'   => config('jwt.ttl') * 60, // seconds
                'user'         => new UserResource($user),
            ],
            'message' => 'Login successful.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/logout
    // -------------------------------------------------------------------------

    /**
     * Invalidate the current JWT by blacklisting its jti in Redis.
     *
     * Requirements: 2.9
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout(
            ipAddress: $request->ip(),
            requestId: $request->header('X-Request-ID'),
        );

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Logged out successfully.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/refresh
    // -------------------------------------------------------------------------

    /**
     * Refresh the current JWT and return a new token.
     * The old token's jti is blacklisted in Redis.
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $newToken = $this->authService->refresh();

            return response()->json([
                'success' => true,
                'data'    => [
                    'access_token' => $newToken,
                    'token_type'   => 'bearer',
                    'expires_in'   => config('jwt.ttl') * 60,
                ],
                'message' => 'Token refreshed successfully.',
                'errors'  => null,
                'meta'    => null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Token refresh failed.',
                'errors'  => null,
                'meta'    => null,
            ], 401);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/me
    // -------------------------------------------------------------------------

    /**
     * Return the authenticated user's profile and JWT claims.
     */
    public function me(Request $request): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user),
            'message' => 'User profile retrieved.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/password/request
    // -------------------------------------------------------------------------

    /**
     * Request a password reset link.
     *
     * Always returns HTTP 200 to prevent user enumeration.
     * The reset link is time-limited to 60 minutes.
     *
     * Requirements: 2.5
     */
    public function requestPasswordReset(PasswordResetRequest $request): JsonResponse
    {
        $tenant = app('tenant');

        $this->authService->requestPasswordReset(
            email: $request->input('email'),
            tenantId: $tenant->id,
            ipAddress: $request->ip(),
            requestId: $request->header('X-Request-ID'),
        );

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'If an account with that email exists, a password reset link has been sent.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/password/reset
    // -------------------------------------------------------------------------

    /**
     * Confirm a password reset using the provided token and new password.
     *
     * Requirements: 2.5, 2.6
     */
    public function resetPassword(PasswordResetConfirmRequest $request): JsonResponse
    {
        $success = $this->authService->resetPassword(
            token: $request->input('token'),
            newPassword: $request->input('password'),
            ipAddress: $request->ip(),
            requestId: $request->header('X-Request-ID'),
        );

        if (! $success) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'The password reset link is invalid or has expired. Please request a new one.',
                'errors'  => ['token' => ['Invalid or expired reset token.']],
                'meta'    => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Password has been reset successfully. You may now log in with your new password.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/csrf-token
    // -------------------------------------------------------------------------

    /**
     * Return a CSRF token for browser-based clients.
     *
     * Generates a cryptographically secure random token that browser clients
     * should include in the X-CSRF-TOKEN header on all state-changing requests.
     * The token is also set as a cookie so JavaScript can read it.
     *
     * For stateless JWT APIs, this provides double-submit cookie CSRF protection:
     * the client reads the token from the response and sends it back as a header.
     *
     * Requirements: 2.7
     */
    public function csrfToken(Request $request): JsonResponse
    {
        // Generate a cryptographically secure CSRF token
        $token = Str::random(40);

        return response()->json([
            'success' => true,
            'data'    => [
                'csrf_token' => $token,
            ],
            'message' => 'CSRF token retrieved.',
            'errors'  => null,
            'meta'    => null,
        ])->cookie('XSRF-TOKEN', $token, 0, '/', null, false, false);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
}
