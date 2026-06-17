<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\User\AssignRoleRequest;
use App\Http\Requests\V1\User\StoreUserRequest;
use App\Http\Requests\V1\User\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * UserController — thin controller for user management within tenant scope.
 *
 * Endpoints:
 *   GET    /api/v1/users                       — list users (paginated, searchable, sortable)
 *   POST   /api/v1/users                       — create user + send welcome email
 *   GET    /api/v1/users/{user}                — show user
 *   PUT    /api/v1/users/{user}                — update user profile
 *   DELETE /api/v1/users/{user}                — delete user (guarded by active PRs/POs)
 *   POST   /api/v1/users/{user}/roles          — assign role
 *   DELETE /api/v1/users/{user}/roles/{role}   — revoke role
 *
 * All routes require `auth.jwt` middleware and appropriate `role.check` permissions.
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 *
 * Requirements: 4.1, 4.2, 4.6, 4.7, 4.8, 4.9
 */
class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $userService)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/users
    // -------------------------------------------------------------------------

    /**
     * Return a paginated, searchable, sortable list of users within the tenant.
     *
     * Query parameters:
     *   search        — filter by name or email (partial match)
     *   status        — filter by status (active|inactive|locked)
     *   department_id — filter by department UUID
     *   role          — filter by role name
     *   sort_by       — column to sort by (name|email|status|created_at|updated_at)
     *   sort_dir      — sort direction (asc|desc)
     *   per_page      — results per page (max 100, default 20)
     *
     * Requirements: 4.6
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->userService->listUsers([
            'search'        => $request->query('search'),
            'status'        => $request->query('status'),
            'department_id' => $request->query('department_id'),
            'role'          => $request->query('role'),
            'sort_by'       => $request->query('sort_by', 'created_at'),
            'sort_dir'      => $request->query('sort_dir', 'desc'),
            'per_page'      => $request->query('per_page', 20),
        ]);

        return response()->json([
            'success' => true,
            'data'    => UserResource::collection($paginator->items()),
            'message' => 'Users retrieved successfully.',
            'errors'  => null,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/users
    // -------------------------------------------------------------------------

    /**
     * Create a new user within the active tenant.
     *
     * Sends a welcome email with a 24-hour password-setup link.
     * Enforces unique email per tenant.
     *
     * Requirements: 4.1, 4.2, 4.8
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->userService->createUser(
            data:      $request->validated(),
            actor:     $actor,
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestId: $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
                'errors'  => ['email' => [$result['message']]],
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => new UserResource($result['data']->load(['department', 'roles', 'permissions'])),
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/users/{user}
    // -------------------------------------------------------------------------

    /**
     * Return a single user within the active tenant.
     *
     * Requirements: 4.1
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new UserResource($user->load(['department', 'roles', 'permissions'])),
            'message' => 'User retrieved successfully.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/users/{user}
    // -------------------------------------------------------------------------

    /**
     * Update a user's profile within the active tenant.
     *
     * Requirements: 4.7
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->userService->updateUser(
            user:      $user,
            data:      $request->validated(),
            actor:     $actor,
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestId: $request->header('X-Request-ID'),
        );

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['data'] ? new UserResource($result['data']->load(['department', 'roles', 'permissions'])) : null,
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ], $result['code']);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/users/{user}
    // -------------------------------------------------------------------------

    /**
     * Delete (soft-delete) a user within the active tenant.
     *
     * Rejects deletion if the user has active PRs or POs, returning the counts.
     *
     * Requirements: 4.1, 4.9
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->userService->deleteUser(
            user:      $user,
            actor:     $actor,
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestId: $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => $result['data'],
                'message' => $result['message'],
                'errors'  => ['deletion' => [$result['message']]],
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/users/{user}/roles
    // -------------------------------------------------------------------------

    /**
     * Assign a role to a user within the active tenant.
     *
     * Requirements: 3.3, 3.5, 3.6, 3.9
     */
    public function assignRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->userService->assignRole(
            user:      $user,
            roleName:  $request->validated()['role'],
            actor:     $actor,
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestId: $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
                'errors'  => ['role' => [$result['message']]],
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data'],
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/users/{user}/roles/{role}
    // -------------------------------------------------------------------------

    /**
     * Revoke a role from a user within the active tenant.
     *
     * Requirements: 3.3, 3.5, 3.6, 3.9
     */
    public function revokeRole(Request $request, User $user, string $role): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->userService->revokeRole(
            user:      $user,
            roleName:  $role,
            actor:     $actor,
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestId: $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
                'errors'  => ['role' => [$result['message']]],
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data'],
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ]);
    }
}
