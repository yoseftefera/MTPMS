<?php

namespace App\Models;

use App\Traits\HasAuditLog;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog, HasRoles, Notifiable, SoftDeletes;

    /**
     * The guard name used by Spatie Laravel Permission.
     * Must match the guard_name used when seeding roles and permissions.
     */
    protected $guard_name = 'api';

    protected $fillable = [
        'name',
        'email',
        'password',
        'department_id',
        'status',
        'failed_login_attempts',
        'avatar',
        'phone',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at'      => 'datetime',
        'failed_login_attempts'  => 'integer',
    ];

    // -------------------------------------------------------------------------
    // JWT Interface
    // -------------------------------------------------------------------------

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'user_id'     => $this->id,
            'tenant_id'   => $this->tenant_id,
            'role'        => $this->getRoleNames()->first(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->toArray(),
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class, 'submitted_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'approver_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
