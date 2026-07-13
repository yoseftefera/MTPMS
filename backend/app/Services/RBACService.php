<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBACService — business logic for role assignment, revocation, and permission management.
 *
 * Responsibilities:
 *  - Assign roles to users within tenant scope
 *  - Revoke roles from users within tenant scope
 *  - Invalidate permission cache within 5 seconds of any role change (Req 3.6)
 *  - Prevent System_Admin role assignment through tenant interface (Req 3.5)
 *  - Dispatch audit log entries for all role changes (Req 3.9)
 *
 * Cache invalidation strategy:
 *  - Calls Spatie's PermissionRegistrar::forgetCachedPermissions() immediately
 *  - Also clears any user-specific permission cache keys
 *  - The combined approach ensures cache is invalidated within 5 seconds (Req 3.6)
 *
 * Requirements: 3.1, 3.2, 3.3, 3.5, 3.6, 3.9
 */
class RBACService
{
    /**
     * Cache TTL for role and user permission sets (seconds).
     * Defaults to 300 seconds (Requirement 24.3).
     * Configurable via PERMISSION_CACHE_TTL env var → cache.permission_ttl.
     */
    private function getPermissionCacheTtl(): int
    {
        return (int) config('cache.permission_ttl', 300);
    }

    /**
     * Roles that cannot be assigned or revoked through the tenant-facing interface.
     *
     * Requirement 3.5
     */
    private const PROTECTED_ROLES = ['System_Admin'];

    /**
     * Assign a role to a user.
     *
     * Validates that:
     *  - The role is not a protected system role (Req 3.5)
     *  - The role exists in the system
     *
     * On success:
     *  - Assigns the role via Spatie
     *  - Invalidates the permission cache immediately (Req 3.6)
     *  - Dispatches an audit log entry (Req 3.9)
     *
     * @param  User         $user       The user to assign the role to
     * @param  string       $roleName   The name of the role to assign
     * @param  User|null    $actor      The user performing the assignment (for audit log)
     * @param  string       $ipAddress  The IP address of the request
     * @param  string|null  $requestId  The X-Request-ID header value
     *
     * @return array{success: bool, message: string, code: int, data: array|null}
     */
    public function assignRole(
        User $user,
        string $roleName,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        // Requirement 3.5 — prevent assigning protected roles through tenant interface
        if (in_array($roleName, self::PROTECTED_ROLES, true)) {
            return [
                'success' => false,
                'message' => "The {$roleName} role cannot be assigned through the tenant interface.",
                'code'    => 403,
                'data'    => null,
            ];
        }

        // Verify the role exists
        $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();

        if (! $role) {
            return [
                'success' => false,
                'message' => "Role [{$roleName}] does not exist.",
                'code'    => 422,
                'data'    => null,
            ];
        }

        // Capture before state for audit log
        $beforeRoles = $user->getRoleNames()->toArray();

        // Assign the role (Spatie handles duplicates gracefully)
        $user->assignRole($role);

        // Requirement 3.6 — invalidate permission cache immediately (within 5 seconds)
        $this->invalidatePermissionCache($user);

        // Requirement 3.9 — dispatch async audit log entry
        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'role_assigned',
            entityId:   $user->id,
            before:     ['roles' => $beforeRoles],
            after:      ['roles' => $user->fresh()->getRoleNames()->toArray(), 'assigned_role' => $roleName],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => "Role [{$roleName}] assigned successfully.",
            'code'    => 200,
            'data'    => [
                'user_id' => $user->id,
                'roles'   => $user->fresh()->getRoleNames()->values(),
            ],
        ];
    }

    /**
     * Revoke a role from a user.
     *
     * Validates that:
     *  - The role is not a protected system role (Req 3.5)
     *  - The user actually has the role
     *
     * On success:
     *  - Revokes the role via Spatie
     *  - Invalidates the permission cache immediately (Req 3.6)
     *  - Dispatches an audit log entry (Req 3.9)
     *
     * @param  User         $user       The user to revoke the role from
     * @param  string       $roleName   The name of the role to revoke
     * @param  User|null    $actor      The user performing the revocation (for audit log)
     * @param  string       $ipAddress  The IP address of the request
     * @param  string|null  $requestId  The X-Request-ID header value
     *
     * @return array{success: bool, message: string, code: int, data: array|null}
     */
    public function revokeRole(
        User $user,
        string $roleName,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        // Requirement 3.5 — prevent revoking protected roles through tenant interface
        if (in_array($roleName, self::PROTECTED_ROLES, true)) {
            return [
                'success' => false,
                'message' => "The {$roleName} role cannot be managed through the tenant interface.",
                'code'    => 403,
                'data'    => null,
            ];
        }

        // Verify the user has this role
        if (! $user->hasRole($roleName)) {
            return [
                'success' => false,
                'message' => "User does not have the [{$roleName}] role.",
                'code'    => 422,
                'data'    => null,
            ];
        }

        // Capture before state for audit log
        $beforeRoles = $user->getRoleNames()->toArray();

        // Revoke the role
        $user->removeRole($roleName);

        // Requirement 3.6 — invalidate permission cache immediately (within 5 seconds)
        $this->invalidatePermissionCache($user);

        // Requirement 3.9 — dispatch async audit log entry
        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'role_revoked',
            entityId:   $user->id,
            before:     ['roles' => $beforeRoles, 'revoked_role' => $roleName],
            after:      ['roles' => $user->fresh()->getRoleNames()->toArray()],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => "Role [{$roleName}] revoked successfully.",
            'code'    => 200,
            'data'    => [
                'user_id' => $user->id,
                'roles'   => $user->fresh()->getRoleNames()->values(),
            ],
        ];
    }

    /**
     * Get all available roles (excluding protected system roles for tenant-facing use).
     *
     * @param  bool  $excludeProtected  Whether to exclude protected roles (default: true)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableRoles(bool $excludeProtected = true): \Illuminate\Database\Eloquent\Collection
    {
        $query = Role::where('guard_name', 'api');

        if ($excludeProtected) {
            $query->whereNotIn('name', self::PROTECTED_ROLES);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Build the canonical Redis cache key for a user's permission set.
     *
     * Key pattern: `tenant:{tenantId}:user:{userId}:permissions`
     *
     * Requirement 24.3
     */
    private function userPermissionCacheKey(User $user): string
    {
        $tenantId = $user->tenant_id ?? (app()->has('tenant') ? app('tenant')->id : 'global');
        return "tenant:{$tenantId}:user:{$user->id}:permissions";
    }

    /**
     * Get all permissions for a given role.
     *
     * Results are cached in Redis under `role:permissions:{roleName}` with a
     * 300-second TTL (Requirement 24.3). The cache is invalidated immediately
     * whenever a role assignment or revocation changes the user's permission set.
     *
     * @param  string  $roleName
     * @return \Illuminate\Database\Eloquent\Collection|null
     */
    public function getRolePermissions(string $roleName): ?\Illuminate\Database\Eloquent\Collection
    {
        $cacheKey = "role:permissions:{$roleName}";

        /** @var \Illuminate\Database\Eloquent\Collection|null $permissions */
        $permissions = Cache::store('redis')->remember(
            $cacheKey,
            $this->getPermissionCacheTtl(),
            function () use ($roleName) {
                $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
                return $role?->permissions;
            },
        );

        return $permissions;
    }

    /**
     * Get all permissions for a specific user.
     *
     * Results are cached in Redis under the canonical key pattern
     * `tenant:{tenantId}:user:{userId}:permissions` with a 300-second TTL
     * (Requirement 24.3). Invalidated immediately on any role change for that
     * user (Requirement 3.6).
     *
     * @param  User  $user
     * @return \Illuminate\Support\Collection
     */
    public function getUserPermissions(User $user): \Illuminate\Support\Collection
    {
        $cacheKey = $this->userPermissionCacheKey($user);

        return Cache::store('redis')->remember(
            $cacheKey,
            $this->getPermissionCacheTtl(),
            fn () => $user->getAllPermissions()->pluck('name'),
        );
    }

    /**
     * Invalidate the Spatie permission cache for a specific user and globally.
     *
     * This ensures that permission checks on subsequent requests reflect the
     * latest role assignments without delay (Req 3.6 — within 5 seconds).
     *
     * Strategy:
     *  1. Call Spatie's PermissionRegistrar::forgetCachedPermissions() to clear
     *     the global permission cache (roles + permissions table data).
     *  2. Clear the canonical user key `tenant:{tenantId}:user:{userId}:permissions`
     *     (Req 24.3).
     *  3. Clear role-level `role:permissions:{roleName}` keys for all roles the
     *     user holds, so callers of getRolePermissions() also get fresh data.
     *
     * Requirements: 3.6, 24.3
     */
    public function invalidatePermissionCache(User $user): void
    {
        try {
            // Clear Spatie's global permission/role cache
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            // Clear legacy Spatie per-cache-store key (belt-and-suspenders)
            Cache::forget('spatie.permission.cache');
            Cache::store('redis')->forget('spatie.permission.cache');

            // Clear user-specific permission cache using canonical key pattern (Requirement 24.3)
            Cache::store('redis')->forget($this->userPermissionCacheKey($user));

            // Clear role-level permission caches for all roles the user holds
            foreach ($user->getRoleNames() as $roleName) {
                Cache::store('redis')->forget("role:permissions:{$roleName}");
            }

            Log::debug('RBACService: permission cache invalidated', [
                'user_id'   => $user->id,
                'cache_key' => $this->userPermissionCacheKey($user),
            ]);
        } catch (\Throwable $e) {
            // Log but never let cache invalidation failure break the role change
            Log::warning('RBACService: failed to invalidate permission cache', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch an async audit log entry for role changes.
     *
     * Requirement 3.9
     */
    private function dispatchAuditLog(
        ?User $actor,
        string $actionType,
        string $entityId,
        array $before,
        array $after,
        string $ipAddress,
        ?string $requestId,
    ): void {
        try {
            WriteAuditLogJob::dispatch(
                tenantId:   $actor?->tenant_id ?? (app()->has('tenant') ? app('tenant')->id : null),
                userId:     $actor?->id,
                userRole:   $actor?->getRoleNames()->first(),
                actionType: $actionType,
                entityType: 'user',
                entityId:   $entityId,
                before:     $before,
                after:      $after,
                ipAddress:  $ipAddress,
                requestId:  $requestId,
            );
        } catch (\Throwable $e) {
            // Never let audit log failure break the role change operation
            Log::error('RBACService: failed to dispatch audit log', [
                'error'       => $e->getMessage(),
                'action_type' => $actionType,
                'entity_id'   => $entityId,
            ]);
        }
    }
}
