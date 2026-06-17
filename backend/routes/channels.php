<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| All channels are private and scoped to a tenant to enforce data isolation.
| Channel authorization verifies the requesting user belongs to the tenant.
|
*/

// Individual user notification channel
// Format: private-tenant.{tenantId}.user.{userId}
Broadcast::channel('tenant.{tenantId}.user.{userId}', function ($user, string $tenantId, string $userId) {
    return (string) $user->tenant_id === $tenantId
        && (string) $user->id === $userId;
});

// Tenant-wide broadcast channel (all users in a tenant)
// Format: private-tenant.{tenantId}
Broadcast::channel('tenant.{tenantId}', function ($user, string $tenantId) {
    return (string) $user->tenant_id === $tenantId;
});

// Approval queue channel (approvers only)
// Format: private-tenant.{tenantId}.approvals
Broadcast::channel('tenant.{tenantId}.approvals', function ($user, string $tenantId) {
    return (string) $user->tenant_id === $tenantId
        && $user->hasAnyPermission(['approve-purchase-request', 'manage-tenders', 'manage-contracts', 'manage-invoices']);
});
