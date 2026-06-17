<?php

namespace App\Services;

use App\Jobs\SendPasswordResetEmailJob;
use App\Jobs\WriteAuditLogJob;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Payload;

/**
 * AuthService — handles all authentication business logic.
 *
 * Responsibilities:
 *  - Login: credential validation, failed-attempt tracking, JWT issuance
 *  - Logout: JWT invalidation via Redis jti blacklist
 *  - Refresh: issue a new JWT from a valid existing token
 *  - Password reset request: generate time-limited token, dispatch email job
 *  - Password reset confirmation: validate token, update password
 *
 * Requirements: 2.1, 2.5, 2.6, 2.9
 */
class AuthService
{
    /**
     * Redis key prefix for blacklisted JTIs.
     */
    private const BLACKLIST_PREFIX = 'jwt:blacklist:';

    /**
     * Redis key prefix for password reset tokens.
     */
    private const RESET_PREFIX = 'pwd:reset:';

    /**
     * Attempt to authenticate a user with the given credentials.
     *
     * Returns an array with the JWT token and user on success.
     * Returns null on failure (invalid credentials or inactive user).
     *
     * Requirements: 2.1
     *
     * @param  string  $email
     * @param  string  $password
     * @param  string  $tenantId  The resolved tenant's UUID
     * @param  string  $ipAddress
     * @param  string|null  $requestId
     * @return array{token: string, user: User}|null
     */
    public function login(
        string $email,
        string $password,
        string $tenantId,
        string $ipAddress,
        ?string $requestId = null,
    ): ?array {
        // Find user scoped to the resolved tenant
        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->first();

        if (! $user) {
            Log::info('Login failed — user not found', [
                'email'     => $email,
                'tenant_id' => $tenantId,
                'ip'        => $ipAddress,
            ]);

            return null;
        }

        // Reject locked accounts immediately (lockout handled in task 3.3)
        if ($user->status === 'locked') {
            $this->dispatchAuditLog(
                tenantId: $tenantId,
                userId: $user->id,
                userRole: $user->getRoleNames()->first(),
                actionType: 'LOGIN_FAILED_LOCKED',
                entityId: $user->id,
                ipAddress: $ipAddress,
                requestId: $requestId,
                after: ['reason' => 'Account is locked'],
            );

            return null;
        }

        // Reject inactive accounts
        if ($user->status === 'inactive') {
            return null;
        }

        // Validate password
        if (! Hash::check($password, $user->password)) {
            // Increment failed attempt counter
            $newAttempts = $user->failed_login_attempts + 1;

            // Determine lockout threshold: tenant setting → default 5
            $tenant    = $user->tenant;
            $threshold = (int) ($tenant?->settings['lockout_threshold'] ?? 5);

            if ($newAttempts >= $threshold) {
                // Lock the account and dispatch password-reset email
                $user->update([
                    'failed_login_attempts' => $newAttempts,
                    'status'                => 'locked',
                ]);

                // Generate a time-limited reset token so the user can unlock via email
                $expiryMinutes = (int) config('app.password_reset_expiry', 60);
                $resetToken    = Str::random(64);
                $redisKey      = self::RESET_PREFIX . $resetToken;

                Redis::setex($redisKey, $expiryMinutes * 60, json_encode([
                    'user_id'   => $user->id,
                    'tenant_id' => $tenantId,
                    'email'     => $user->email,
                ]));

                $resetUrl = rtrim(config('app.url'), '/') . '/reset-password?token=' . $resetToken;

                SendPasswordResetEmailJob::dispatch(
                    userEmail: $user->email,
                    userName: $user->name,
                    resetToken: $resetToken,
                    resetUrl: $resetUrl,
                );

                $this->dispatchAuditLog(
                    tenantId: $tenantId,
                    userId: $user->id,
                    userRole: $user->getRoleNames()->first(),
                    actionType: 'ACCOUNT_LOCKED',
                    entityId: $user->id,
                    ipAddress: $ipAddress,
                    requestId: $requestId,
                    after: [
                        'reason'           => 'Failed login threshold reached',
                        'failed_attempts'  => $newAttempts,
                        'threshold'        => $threshold,
                    ],
                );
            } else {
                $user->update(['failed_login_attempts' => $newAttempts]);

                $this->dispatchAuditLog(
                    tenantId: $tenantId,
                    userId: $user->id,
                    userRole: $user->getRoleNames()->first(),
                    actionType: 'LOGIN_FAILED_INVALID_PASSWORD',
                    entityId: $user->id,
                    ipAddress: $ipAddress,
                    requestId: $requestId,
                    after: [
                        'reason'          => 'Invalid password',
                        'failed_attempts' => $newAttempts,
                    ],
                );
            }

            return null;
        }

        // Issue JWT — custom claims are populated via User::getJWTCustomClaims()
        $token = JWTAuth::fromUser($user);

        // Reset failed attempts on successful login
        if ($user->failed_login_attempts > 0) {
            $user->update(['failed_login_attempts' => 0]);
        }

        $this->dispatchAuditLog(
            tenantId: $tenantId,
            userId: $user->id,
            userRole: $user->getRoleNames()->first(),
            actionType: 'LOGIN_SUCCESS',
            entityId: $user->id,
            ipAddress: $ipAddress,
            requestId: $requestId,
            after: ['email' => $user->email],
        );

        return [
            'token' => $token,
            'user'  => $user,
        ];
    }

    /**
     * Invalidate the current JWT by storing its jti in Redis.
     *
     * TTL is set to the remaining lifetime of the token so the blacklist
     * entry expires automatically when the token would have expired anyway.
     *
     * Requirements: 2.9
     *
     * @param  string  $ipAddress
     * @param  string|null  $requestId
     */
    public function logout(string $ipAddress, ?string $requestId = null): void
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $jti     = $payload->get('jti');
            $exp     = $payload->get('exp');
            $userId  = $payload->get('user_id') ?? $payload->get('sub');
            $tenantId = $payload->get('tenant_id');
            $role    = $payload->get('role');

            if ($jti && $exp) {
                $ttl = max(0, $exp - now()->timestamp);

                if ($ttl > 0) {
                    Redis::setex(self::BLACKLIST_PREFIX . $jti, $ttl, '1');
                }
            }

            // Invalidate via tymon's built-in blacklist as well
            JWTAuth::parseToken()->invalidate();

            $this->dispatchAuditLog(
                tenantId: $tenantId,
                userId: $userId,
                userRole: $role,
                actionType: 'LOGOUT',
                entityId: $userId,
                ipAddress: $ipAddress,
                requestId: $requestId,
                after: ['jti' => $jti],
            );
        } catch (\Throwable $e) {
            Log::warning('Logout: could not invalidate token', [
                'error' => $e->getMessage(),
                'ip'    => $ipAddress,
            ]);
        }
    }

    /**
     * Refresh the current JWT and blacklist the old one.
     *
     * @return string  The new JWT token string
     */
    public function refresh(): string
    {
        // Blacklist the old token's jti before issuing a new one
        try {
            $oldPayload = JWTAuth::parseToken()->getPayload();
            $oldJti     = $oldPayload->get('jti');
            $oldExp     = $oldPayload->get('exp');

            if ($oldJti && $oldExp) {
                $ttl = max(0, $oldExp - now()->timestamp);
                if ($ttl > 0) {
                    Redis::setex(self::BLACKLIST_PREFIX . $oldJti, $ttl, '1');
                }
            }
        } catch (\Throwable) {
            // Continue — tymon will handle the refresh
        }

        return JWTAuth::parseToken()->refresh();
    }

    /**
     * Check whether a given jti has been blacklisted in Redis.
     *
     * @param  string  $jti
     * @return bool
     */
    public function isBlacklisted(string $jti): bool
    {
        return (bool) Redis::exists(self::BLACKLIST_PREFIX . $jti);
    }

    /**
     * Initiate a password reset for the given email address.
     *
     * Generates a cryptographically secure token, stores it in Redis with a
     * 60-minute TTL, and dispatches a queued email job.
     *
     * Requirements: 2.5
     *
     * @param  string  $email
     * @param  string  $tenantId
     * @param  string  $ipAddress
     * @param  string|null  $requestId
     * @return bool  True if the user was found and email dispatched; false otherwise
     */
    public function requestPasswordReset(
        string $email,
        string $tenantId,
        string $ipAddress,
        ?string $requestId = null,
    ): bool {
        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->first();

        if (! $user) {
            // Return true to avoid user enumeration — do not reveal whether email exists
            return true;
        }

        $expiryMinutes = (int) config('app.password_reset_expiry', 60);
        $token         = Str::random(64);
        $redisKey      = self::RESET_PREFIX . $token;

        // Store token → user_id mapping with TTL
        Redis::setex($redisKey, $expiryMinutes * 60, json_encode([
            'user_id'   => $user->id,
            'tenant_id' => $tenantId,
            'email'     => $user->email,
        ]));

        $resetUrl = rtrim(config('app.url'), '/') . '/reset-password?token=' . $token;

        SendPasswordResetEmailJob::dispatch(
            userEmail: $user->email,
            userName: $user->name,
            resetToken: $token,
            resetUrl: $resetUrl,
        );

        $this->dispatchAuditLog(
            tenantId: $tenantId,
            userId: $user->id,
            userRole: $user->getRoleNames()->first(),
            actionType: 'PASSWORD_RESET_REQUESTED',
            entityId: $user->id,
            ipAddress: $ipAddress,
            requestId: $requestId,
            after: ['email' => $user->email],
        );

        return true;
    }

    /**
     * Confirm a password reset using the provided token and new password.
     *
     * Requirements: 2.5, 2.6
     *
     * @param  string  $token
     * @param  string  $newPassword
     * @param  string  $ipAddress
     * @param  string|null  $requestId
     * @return bool  True on success; false if token is invalid or expired
     */
    public function resetPassword(
        string $token,
        string $newPassword,
        string $ipAddress,
        ?string $requestId = null,
    ): bool {
        $redisKey = self::RESET_PREFIX . $token;
        $stored   = Redis::get($redisKey);

        if (! $stored) {
            // Token not found or expired
            return false;
        }

        $data = json_decode($stored, true);

        if (! isset($data['user_id'], $data['tenant_id'])) {
            return false;
        }

        $user = User::withoutGlobalScopes()->find($data['user_id']);

        if (! $user) {
            return false;
        }

        // Update password and unlock account if it was locked
        $user->update([
            'password'              => Hash::make($newPassword),
            'failed_login_attempts' => 0,
            'status'                => $user->status === 'locked' ? 'active' : $user->status,
        ]);

        // Consume the token — one-time use
        Redis::del($redisKey);

        $this->dispatchAuditLog(
            tenantId: $data['tenant_id'],
            userId: $user->id,
            userRole: $user->getRoleNames()->first(),
            actionType: 'PASSWORD_RESET_COMPLETED',
            entityId: $user->id,
            ipAddress: $ipAddress,
            requestId: $requestId,
            after: ['email' => $user->email],
        );

        return true;
    }

    /**
     * Dispatch an async audit log entry.
     */
    private function dispatchAuditLog(
        ?string $tenantId,
        ?string $userId,
        ?string $userRole,
        string $actionType,
        ?string $entityId,
        string $ipAddress,
        ?string $requestId,
        ?array $after = null,
    ): void {
        try {
            WriteAuditLogJob::dispatch(
                tenantId: $tenantId,
                userId: $userId,
                userRole: $userRole,
                actionType: $actionType,
                entityType: 'user',
                entityId: $entityId,
                before: null,
                after: $after,
                ipAddress: $ipAddress,
                requestId: $requestId,
            );
        } catch (\Throwable $e) {
            Log::error('AuthService: failed to dispatch audit log', [
                'error'       => $e->getMessage(),
                'action_type' => $actionType,
            ]);
        }
    }
}
