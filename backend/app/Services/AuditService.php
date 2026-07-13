<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;

/**
 * AuditService — thin wrapper around WriteAuditLogJob.
 *
 * Application code that needs to record an audit event (e.g. inside a
 * domain service) should inject AuditService and call log() rather than
 * dispatching WriteAuditLogJob directly.  This keeps the coupling to the
 * job implementation in one place and makes unit-testing easier.
 *
 * Requirements: 17.1, 17.2, 17.3, 17.4, 17.5
 */
class AuditService
{
    /**
     * Dispatch an asynchronous audit log entry.
     *
     * @param  string       $actionType  Dot-notation action string, e.g. 'supplier.blacklisted'
     * @param  string       $entityType  Entity being acted on, e.g. 'suppliers'
     * @param  string|null  $entityId    UUID of the entity (nullable for bulk/system actions)
     * @param  array|null   $before      Snapshot of entity state before the action
     * @param  array|null   $after       Snapshot of entity state after the action
     * @param  string|null  $tenantId    Resolved tenant UUID (nullable for system-level actions)
     * @param  string|null  $userId      Authenticated user UUID
     * @param  string|null  $userRole    Primary role name of the authenticated user
     * @param  string       $ipAddress   Request IP address
     * @param  string|null  $requestId   X-Request-ID header value
     */
    public function log(
        string  $actionType,
        string  $entityType,
        ?string $entityId   = null,
        ?array  $before     = null,
        ?array  $after      = null,
        ?string $tenantId   = null,
        ?string $userId     = null,
        ?string $userRole   = null,
        string  $ipAddress  = '',
        ?string $requestId  = null,
    ): void {
        WriteAuditLogJob::dispatch(
            tenantId:   $tenantId,
            userId:     $userId,
            userRole:   $userRole,
            actionType: $actionType,
            entityType: $entityType,
            entityId:   $entityId,
            before:     $before,
            after:      $after,
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );
    }
}
