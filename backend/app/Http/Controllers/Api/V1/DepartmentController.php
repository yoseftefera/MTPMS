<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Department\StoreDepartmentRequest;
use App\Http\Requests\V1\Department\UpdateDepartmentRequest;
use App\Http\Resources\V1\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(name="Departments", description="Department management within tenant scope.")
 *
 * DepartmentController — thin controller for department management within tenant scope.
 *
 * Endpoints:
 *   GET    /api/v1/departments                          — list departments (paginated, searchable)
 *   POST   /api/v1/departments                          — create department
 *   GET    /api/v1/departments/{department}             — show department
 *   PUT    /api/v1/departments/{department}             — update department
 *   DELETE /api/v1/departments/{department}             — soft-delete department
 *   PATCH  /api/v1/departments/{department}/status      — activate or deactivate department
 *   GET    /api/v1/departments/hierarchy                — full nested hierarchy tree
 *
 * All routes require `auth.jwt` middleware and `role.check:departments.view` permission.
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 *
 * Requirements: 4.3, 4.4, 4.5
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $departmentService)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/departments
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/departments", operationId="listDepartments", tags={"Departments"}, summary="List departments",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"active","inactive"})),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Departments list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DepartmentResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated, searchable list of departments within the tenant.
     *
     * Requirements: 4.3
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->departmentService->list([
            'search'    => $request->query('search'),
            'status'    => $request->query('status'),
            'parent_id' => $request->query('parent_id'),
            'sort_by'   => $request->query('sort_by', 'name'),
            'sort_dir'  => $request->query('sort_dir', 'asc'),
            'per_page'  => $request->query('per_page', 20),
        ]);

        return response()->json([
            'success' => true,
            'data'    => DepartmentResource::collection($paginator->items()),
            'message' => 'Departments retrieved successfully.',
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
    // POST /api/v1/departments
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/departments", operationId="createDepartment", tags={"Departments"}, summary="Create department",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"name","code"}, @OA\Property(property="name", type="string", example="Finance"), @OA\Property(property="code", type="string", example="FIN"), @OA\Property(property="parent_id", type="string", format="uuid", nullable=true))),
     *     @OA\Response(response=201, description="Department created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/DepartmentResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Create a new department within the active tenant.
     *
     * Requirements: 4.3, 4.5
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->departmentService->create(
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
                'errors'  => $result['errors'] ?? null,
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($result['data']),
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/departments/{department}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/departments/{department}", operationId="showDepartment", tags={"Departments"}, summary="Get department",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Department returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/DepartmentResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single department within the active tenant.
     *
     * Requirements: 4.3
     */
    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($department->load(['parent', 'children'])),
            'message' => 'Department retrieved successfully.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/departments/{department}
    // -------------------------------------------------------------------------

    /**
     * @OA\Put(path="/departments/{department}", operationId="updateDepartment", tags={"Departments"}, summary="Update department",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="code", type="string"), @OA\Property(property="parent_id", type="string", format="uuid", nullable=true))),
     *     @OA\Response(response=200, description="Department updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/DepartmentResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Update a department's name, code, or parent within the active tenant.
     *
     * Requirements: 4.3, 4.5
     */
    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->departmentService->update(
            department: $department,
            data:       $request->validated(),
            actor:      $actor,
            ipAddress:  $request->ip() ?? '0.0.0.0',
            requestId:  $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
                'errors'  => $result['errors'] ?? null,
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($result['data']),
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/departments/{department}
    // -------------------------------------------------------------------------

    /**
     * @OA\Delete(path="/departments/{department}", operationId="deleteDepartment", tags={"Departments"}, summary="Delete department",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Department deleted.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=409, description="Cannot delete — has children or active users.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Soft-delete a department within the active tenant.
     *
     * Requirements: 4.3, 4.4
     */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->departmentService->destroy(
            department: $department,
            actor:      $actor,
            ipAddress:  $request->ip() ?? '0.0.0.0',
            requestId:  $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => $result['data'],
                'message' => $result['message'],
                'errors'  => $result['errors'] ?? ['deletion' => [$result['message']]],
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
    // PATCH /api/v1/departments/{department}/status
    // -------------------------------------------------------------------------

    /**
     * @OA\Patch(path="/departments/{department}/status", operationId="updateDepartmentStatus", tags={"Departments"}, summary="Activate or deactivate department",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="department", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"action"}, @OA\Property(property="action", type="string", enum={"activate","deactivate"}))),
     *     @OA\Response(response=200, description="Status updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/DepartmentResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid action.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Activate or deactivate a department.
     *
     * Requirements: 4.4
     */
    public function updateStatus(Request $request, Department $department): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:activate,deactivate'],
        ]);

        $actor  = Auth::guard('api')->user();
        $action = $request->input('action');

        $result = $action === 'deactivate'
            ? $this->departmentService->deactivate(
                department: $department,
                actor:      $actor,
                ipAddress:  $request->ip() ?? '0.0.0.0',
                requestId:  $request->header('X-Request-ID'),
            )
            : $this->departmentService->restore(
                department: $department,
                actor:      $actor,
                ipAddress:  $request->ip() ?? '0.0.0.0',
                requestId:  $request->header('X-Request-ID'),
            );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
                'errors'  => $result['errors'] ?? null,
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($result['data']),
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/departments/hierarchy
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/departments/hierarchy", operationId="departmentHierarchy", tags={"Departments"}, summary="Get department hierarchy tree",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(response=200, description="Nested department tree.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DepartmentResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null)))
     * )
     *
     * Return the full department hierarchy as a nested tree.
     *
     * Requirements: 4.5
     */
    public function hierarchy(): JsonResponse
    {
        $tree = $this->departmentService->getHierarchy();

        return response()->json([
            'success' => true,
            'data'    => DepartmentResource::collection($tree),
            'message' => 'Department hierarchy retrieved successfully.',
            'errors'  => null,
            'meta'    => null,
        ]);
    }
}
