<?php

namespace App\Services;

use App\Jobs\SendWelcomeEmailJob;
use App\Jobs\WriteAuditLogJob;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * UserManagementService — business logic for user CRUD within tenant scope.
 *
 * Responsibilities:
 *  - Create users with unique email per tenant (Req 4.8)
 *  - Send welcome email with 24-hour password-setup link on creation (Req 4.2)
 *  - Read / list users with pagination, search, and sort (Req 4.6)
 *  - Update user profile (Req 4.7)
 *  - Deactivate / delete users with guard for active PRs or POs (Req 4.9)
 *  - Role assignment and revocation delegated to RBACService (Req 3.3)
 *  - Dispatch audit log entries for all mutations
 *
 * Requirements: 4.1, 4.2, 4.6, 4.7, 4.8, 4.9
 */
class UserManagementService
{
    /**
     * Redis key prefix for password-setup tokens (24-hour TTL).
     */
    private const SETUP_PREFIX = 'pwd:setup:';

    /**
     * Active PR statuses that block user deletion.
     */
    private const ACTIVE_PR_STATUSES = ['draft', 'pending_approval', 'revision_required'];

    /**
     * Active PO statuses that block user deletion.
     */
    private const ACTIVE_PO_STATUSES = ['draft', 'issued', 'accepted', 'partially_received'];

    public function __construct(private readonly RBACService $rbacService)
    {
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a new user within the active tenant.
     *
     * Enforces unique email per tenant (Req 4.8).
     * Dispatches a welcome email with a 24-hour password-setup link (Req 4.2).
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     department_id?: string|null,
     *     phone?: string|null,
     *     role?: string|null,
     * }  $data
     * @param  User|null  $actor
     * @param  string     $ipAddress
     * @param  string|null $requestId
     *
     * @return array{success: bool, message: string, code: int, data: User|null}
     */
    public function createUser(
        array $data,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $tenant = app('tenant');

        // Requirement 4.8 — unique email per tenant
        $emailExists = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->whereNull('deleted_at')
            ->exists();

        if ($emailExists) {
            return [
                'success' => false,
                'message' => 'A user with this email address already exists in this organisation.',
                'code'    => 422,
                'data'    => null,
            ];
        }

        // Generate a temporary placeholder password — user will set their own via the setup link
        $temporaryPassword = Hash::make(Str::random(32));

        $user = User::create([
            'name'          => $data['name'],
            'email'         => $data['email'],
            'password'      => $temporaryPassword,
            'department_id' => $data['department_id'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'status'        => 'active',
        ]);

        // Assign role if provided
        if (! empty($data['role'])) {
            $this->rbacService->assignRole(
                user:      $user,
                roleName:  $data['role'],
                actor:     $actor,
                ipAddress: $ipAddress,
                requestId: $requestId,
            );
        }

        // Requirement 4.2 — dispatch welcome email with 24-hour password-setup link
        $this->dispatchWelcomeEmail($user, $tenant);

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'user_created',
            entityId:   $user->id,
            before:     null,
            after:      [
                'name'          => $user->name,
                'email'         => $user->email,
                'department_id' => $user->department_id,
                'status'        => $user->status,
            ],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'User created successfully. A welcome email has been sent.',
            'code'    => 201,
            'data'    => $user->fresh(),
        ];
    }

    // -------------------------------------------------------------------------
    // Read / List
    // -------------------------------------------------------------------------

    /**
     * Return a paginated, searchable, sortable list of users within the tenant.
     *
     * Requirement 4.6
     *
     * @param  array{
     *     search?: string|null,
     *     status?: string|null,
     *     department_id?: string|null,
     *     role?: string|null,
     *     sort_by?: string,
     *     sort_dir?: string,
     *     per_page?: int,
     * }  $filters
     */
    public function listUsers(array $filters = []): LengthAwarePaginator
    {
        $query = User::with(['department', 'roles', 'permissions']);

        // Search by name or email
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by department
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        // Filter by role (via Spatie)
        if (! empty($filters['role'])) {
            $query->role($filters['role']);
        }

        // Sorting
        $allowedSortColumns = ['name', 'email', 'status', 'created_at', 'updated_at'];
        $sortBy  = in_array($filters['sort_by'] ?? '', $allowedSortColumns, true)
            ? $filters['sort_by']
            : 'created_at';
        $sortDir = strtolower($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    /**
     * Find a single user by ID within the active tenant.
     *
     * @return array{success: bool, message: string, code: int, data: User|null}
     */
    public function findUser(string $userId): array
    {
        $user = User::with(['department', 'roles'])->find($userId);

        if (! $user) {
            return [
                'success' => false,
                'message' => 'User not found.',
                'code'    => 404,
                'data'    => null,
            ];
        }

        return [
            'success' => true,
            'message' => 'User retrieved successfully.',
            'code'    => 200,
            'data'    => $user,
        ];
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Update a user's profile within the active tenant.
     *
     * Requirement 4.7
     *
     * @param  array{
     *     name?: string,
     *     phone?: string|null,
     *     avatar?: string|null,
     *     department_id?: string|null,
     *     status?: string,
     * }  $data
     *
     * @return array{success: bool, message: string, code: int, data: User|null}
     */
    public function updateUser(
        User $user,
        array $data,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $before = $user->only(['name', 'phone', 'avatar', 'department_id', 'status']);

        $fillable = array_filter([
            'name'          => $data['name'] ?? null,
            'phone'         => array_key_exists('phone', $data) ? $data['phone'] : null,
            'avatar'        => array_key_exists('avatar', $data) ? $data['avatar'] : null,
            'department_id' => array_key_exists('department_id', $data) ? $data['department_id'] : null,
            'status'        => $data['status'] ?? null,
        ], fn ($v) => $v !== null);

        // Allow explicit null for nullable fields
        if (array_key_exists('phone', $data)) {
            $fillable['phone'] = $data['phone'];
        }
        if (array_key_exists('avatar', $data)) {
            $fillable['avatar'] = $data['avatar'];
        }
        if (array_key_exists('department_id', $data)) {
            $fillable['department_id'] = $data['department_id'];
        }

        $user->update($fillable);

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'user_updated',
            entityId:   $user->id,
            before:     $before,
            after:      $user->fresh()->only(['name', 'phone', 'avatar', 'department_id', 'status']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'User updated successfully.',
            'code'    => 200,
            'data'    => $user->fresh(),
        ];
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    /**
     * Delete (soft-delete) a user within the active tenant.
     *
     * Requirement 4.9 — reject deletion if user has active PRs or POs.
     *
     * @return array{success: bool, message: string, code: int, data: array|null}
     */
    public function deleteUser(
        User $user,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        // Requirement 4.9 — guard: count active PRs and POs linked to this user
        $activePrCount = PurchaseRequest::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('submitted_by', $user->id)
            ->whereIn('status', self::ACTIVE_PR_STATUSES)
            ->count();

        $activePoCount = PurchaseOrder::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('created_by', $user->id)
            ->whereIn('status', self::ACTIVE_PO_STATUSES)
            ->count();

        if ($activePrCount > 0 || $activePoCount > 0) {
            return [
                'success' => false,
                'message' => 'Cannot delete user with active purchase requests or purchase orders.',
                'code'    => 422,
                'data'    => [
                    'active_purchase_requests' => $activePrCount,
                    'active_purchase_orders'   => $activePoCount,
                ],
            ];
        }

        $before = $user->only(['name', 'email', 'status']);

        $user->delete();

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'user_deleted',
            entityId:   $user->id,
            before:     $before,
            after:      ['deleted_at' => now()->toIso8601String()],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'User deleted successfully.',
            'code'    => 200,
            'data'    => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Role management (delegates to RBACService)
    // -------------------------------------------------------------------------

    /**
     * Assign a role to a user.
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
        return $this->rbacService->assignRole(
            user:      $user,
            roleName:  $roleName,
            actor:     $actor,
            ipAddress: $ipAddress,
            requestId: $requestId,
        );
    }

    /**
     * Revoke a role from a user.
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
        return $this->rbacService->revokeRole(
            user:      $user,
            roleName:  $roleName,
            actor:     $actor,
            ipAddress: $ipAddress,
            requestId: $requestId,
        );
    }

    // -------------------------------------------------------------------------
    // Welcome email
    // -------------------------------------------------------------------------

    /**
     * Generate a 24-hour password-setup token, store it in Redis, and dispatch
     * the welcome email job.
     *
     * Requirement 4.2
     */
    private function dispatchWelcomeEmail(User $user, \App\Models\Tenant $tenant): void
    {
        try {
            $token    = Str::random(64);
            $redisKey = self::SETUP_PREFIX . $token;
            $ttl      = 24 * 60 * 60; // 24 hours in seconds

            Redis::setex($redisKey, $ttl, json_encode([
                'user_id'   => $user->id,
                'tenant_id' => $tenant->id,
                'email'     => $user->email,
            ]));

            $setupUrl = rtrim(config('app.url'), '/') . '/setup-password?token=' . $token;

            SendWelcomeEmailJob::dispatch(
                userEmail:  $user->email,
                userName:   $user->name,
                setupToken: $token,
                setupUrl:   $setupUrl,
                tenantName: $tenant->name,
            );
        } catch (\Throwable $e) {
            // Log but never let email dispatch failure break user creation
            Log::error('UserManagementService: failed to dispatch welcome email', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    /**
     * Dispatch an async audit log entry.
     */
    private function dispatchAuditLog(
        ?User $actor,
        string $actionType,
        string $entityId,
        ?array $before,
        ?array $after,
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
            Log::error('UserManagementService: failed to dispatch audit log', [
                'error'       => $e->getMessage(),
                'action_type' => $actionType,
                'entity_id'   => $entityId,
            ]);
        }
    }
}
