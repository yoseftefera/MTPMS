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
     * Get all permissions for a given role.
     *
     * @param  string  $roleName
     * @return \Illuminate\Database\Eloquent\Collection|null
     */
    public function getRolePermissions(string $roleName): ?\Illuminate\Database\Eloquent\Collection
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();

        return $role?->permissions;
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
     *  2. Clear any user-specific cache keys that Spatie may have stored.
     *
     * Requirement 3.6
     */
    public function invalidatePermissionCache(User $user): void
    {
        try {
            // Clear Spatie's global permission/role cache
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            // Clear any user-specific permission cache entries
            // Spatie caches per-user permissions under a key derived from the user's ID
            $cacheKey = 'spatie.permission.cache';
            Cache::forget($cacheKey);

            // Also clear the user-model-level cache if Spatie uses it
            $userCacheKey = sprintf('spatie.permission.user.%s', $user->id);
            Cache::forget($userCacheKey);

            Log::debug('RBACService: permission cache invalidated', [
                'user_id' => $user->id,
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
