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
 * @OA\Tag(name="Authentication", description="Login, logout, token refresh, and password reset.")
 *
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
     * @OA\Post(
     *     path="/auth/login",
     *     operationId="authLogin",
     *     tags={"Authentication"},
     *     summary="Authenticate user and issue JWT",
     *     description="Validates credentials for the active tenant and returns a signed JWT. The JWT payload includes: user_id, tenant_id, role, permissions, iat, exp, jti.",
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="jane.doe@acme.com"),
     *             @OA\Property(property="password", type="string", format="password", example="secret123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful — JWT issued.",
     *     @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="access_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."),
     *                 @OA\Property(property="token_type", type="string", example="bearer"),
     *                 @OA\Property(property="expires_in", type="integer", example=86400),
     *                 @OA\Property(property="user", ref="#/components/schemas/UserResource")
     *             ),
     *             @OA\Property(property="message", type="string", example="Login successful."),
     *             @OA\Property(property="errors", nullable=true, example=null),
     *             @OA\Property(property="meta", nullable=true, example=null)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials.",
     *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Too many requests — rate limit exceeded (60/min per IP).",
     *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
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
     * @OA\Post(
     *     path="/auth/logout",
     *     operationId="authLogout",
     *     tags={"Authentication"},
     *     summary="Logout — invalidate JWT",
     *     description="Blacklists the current JWT's jti in Redis so it cannot be reused. Records logout in the audit log.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(
     *         response=200,
     *         description="Logged out successfully.",
     *     @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Post(
     *     path="/auth/refresh",
     *     operationId="authRefresh",
     *     tags={"Authentication"},
     *     summary="Refresh JWT",
     *     description="Issues a new JWT and blacklists the old token's jti.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(
     *         response=200,
     *         description="Token refreshed.",
     *     @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="access_token", type="string"),
     *                 @OA\Property(property="token_type", type="string", example="bearer"),
     *                 @OA\Property(property="expires_in", type="integer", example=86400)
     *             ),
     *             @OA\Property(property="message", type="string", example="Token refreshed successfully."),
     *             @OA\Property(property="errors", nullable=true, example=null),
     *             @OA\Property(property="meta", nullable=true, example=null)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Invalid or expired token.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Get(
     *     path="/auth/me",
     *     operationId="authMe",
     *     tags={"Authentication"},
     *     summary="Get authenticated user profile",
     *     description="Returns the full profile of the currently authenticated user.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(
     *         response=200,
     *         description="User profile returned.",
     *     @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/UserResource"),
     *             @OA\Property(property="message", type="string", example="User profile retrieved."),
     *             @OA\Property(property="errors", nullable=true, example=null),
     *             @OA\Property(property="meta", nullable=true, example=null)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Post(
     *     path="/auth/password/reset-request",
     *     operationId="authPasswordRequest",
     *     tags={"Authentication"},
     *     summary="Request password reset link",
     *     description="Sends a 60-minute reset link to the registered email. Always returns HTTP 200 to prevent user enumeration.",
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="jane.doe@acme.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="If account exists, reset link sent.",
     *     @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     )
     * )
     *
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
     * @OA\Post(
     *     path="/auth/password/reset-confirm",
     *     operationId="authPasswordReset",
     *     tags={"Authentication"},
     *     summary="Confirm password reset",
     *     description="Resets the user's password using the token from the reset email. Token is valid for 60 minutes.",
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token","password","password_confirmation"},
     *             @OA\Property(property="token", type="string", example="a1b2c3d4e5..."),
     *             @OA\Property(property="password", type="string", format="password", example="NewSecure#1234"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="NewSecure#1234")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password reset successfully.",
     *     @OA\JsonContent(ref="#/components/schemas/SuccessResponse")
     *     ),
     *     @OA\Response(response=422, description="Invalid or expired token.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Get(
     *     path="/auth/csrf-token",
     *     operationId="authCsrfToken",
     *     tags={"Authentication"},
     *     summary="Get CSRF token",
     *     description="Returns a CSRF token for browser-based clients. The token is also set as an XSRF-TOKEN cookie.",
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(
     *         response=200,
     *         description="CSRF token returned.",
     *     @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="csrf_token", type="string", example="abc123def456...")
     *             ),
     *             @OA\Property(property="message", type="string", example="CSRF token retrieved."),
     *             @OA\Property(property="errors", nullable=true, example=null),
     *             @OA\Property(property="meta", nullable=true, example=null)
     *         )
     *     )
     * )
     *
     * Return a CSRF token for browser-based clients.
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
