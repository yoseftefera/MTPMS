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
 * @OA\Tag(name="Users", description="User management within tenant scope.")
 *
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
     * @OA\Get(
     *     path="/users",
     *     operationId="listUsers",
     *     tags={"Users"},
     *     summary="List users",
     *     description="Returns a paginated, searchable, sortable list of users within the active tenant.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="Filter by name or email (partial match)."),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"active","inactive","locked"})),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="role", in="query", required=false, @OA\Schema(type="string"), description="Filter by role name."),
     *     @OA\Parameter(name="sort_by", in="query", required=false, @OA\Schema(type="string", default="created_at")),
     *     @OA\Parameter(name="sort_dir", in="query", required=false, @OA\Schema(type="string", enum={"asc","desc"}, default="desc")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20, maximum=100)),
     *     @OA\Response(
     *         response=200,
     *         description="Users list returned.",
     *     @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/UserResource")),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", nullable=true, example=null),
     *             @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated, searchable, sortable list of users within the tenant.
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
     * @OA\Post(
     *     path="/users",
     *     operationId="createUser",
     *     tags={"Users"},
     *     summary="Create user",
     *     description="Creates a new user within the active tenant. Sends a welcome email with a 24-hour password-setup link.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","role"},
     *             @OA\Property(property="name", type="string", example="Jane Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="jane.doe@acme.com"),
     *             @OA\Property(property="role", type="string", example="Procurement_Officer"),
     *             @OA\Property(property="department_id", type="string", format="uuid", nullable=true),
     *             @OA\Property(property="phone", type="string", nullable=true, example="+1-555-0100")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User created.",
     *     @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/UserResource"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", nullable=true, example=null),
     *             @OA\Property(property="meta", nullable=true, example=null)
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Create a new user within the active tenant.
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
     * @OA\Get(
     *     path="/users/{user}",
     *     operationId="showUser",
     *     tags={"Users"},
     *     summary="Get user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="User returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/UserResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Put(
     *     path="/users/{user}",
     *     operationId="updateUser",
     *     tags={"Users"},
     *     summary="Update user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="phone", type="string", nullable=true), @OA\Property(property="department_id", type="string", format="uuid", nullable=true), @OA\Property(property="status", type="string", enum={"active","inactive"}))),
     *     @OA\Response(response=200, description="User updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/UserResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Delete(
     *     path="/users/{user}",
     *     operationId="deleteUser",
     *     tags={"Users"},
     *     summary="Delete user",
     *     description="Soft-deletes the user. Rejected if user has active PRs or POs.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="User deleted.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=409, description="User has active records.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Delete (soft-delete) a user within the active tenant.
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
     * @OA\Post(
     *     path="/users/{user}/roles",
     *     operationId="assignRole",
     *     tags={"Users"},
     *     summary="Assign role to user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"role"}, @OA\Property(property="role", type="string", example="Finance_Officer"))),
     *     @OA\Response(response=200, description="Role assigned.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Delete(
     *     path="/users/{user}/roles/{role}",
     *     operationId="revokeRole",
     *     tags={"Users"},
     *     summary="Revoke role from user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="role", in="path", required=true, @OA\Schema(type="string"), description="Role name to revoke."),
     *     @OA\Response(response=200, description="Role revoked.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
