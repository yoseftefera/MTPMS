<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log model.
 *
 * This model intentionally does NOT use HasTenantScope or HasAuditLog traits:
 * - No tenant scope: System_Admin must be able to query across all tenants.
 * - No audit log: Auditing the audit log would cause infinite recursion.
 * - No updated_at / deleted_at: The table is append-only by design.
 */
class AuditLog extends Model
{
    use HasUuids;

    /**
     * Disable automatic timestamp management.
     * The table only has created_at (set via useCurrent() in migration).
     */
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'user_role',
        'action',
        'entity_type',
        'entity_id',
        'before_data',
        'after_data',
        'ip_address',
        'request_id',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data'  => 'array',
        'created_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The tenant this audit log entry belongs to.
     * Note: No HasTenantScope — System_Admin queries across all tenants.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The user who performed the action (nullable — system actions have no user).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
