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
     * Return a paginated, searchable list of departments within the tenant.
     *
     * Query parameters:
     *   search    — filter by name or code (partial match)
     *   status    — filter by status (active|inactive)
     *   parent_id — filter by parent department UUID (pass 'null' for root departments)
     *   sort_by   — column to sort by (name|code|status|created_at|updated_at)
     *   sort_dir  — sort direction (asc|desc)
     *   per_page  — results per page (max 100, default 20)
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
     * Create a new department within the active tenant.
     *
     * Enforces unique department code per tenant.
     * Validates parent_id belongs to the same tenant when provided.
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
     * Soft-delete a department within the active tenant.
     *
     * Guards against deletion if the department has child departments or active users.
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
     * Activate or deactivate a department.
     *
     * Request body: { "action": "activate" | "deactivate" }
     *
     * Deactivating preserves all historical records.
     * Deactivated departments block new PR submissions (enforced by DepartmentService).
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
     * Return the full department hierarchy as a nested tree.
     *
     * Root departments are returned at the top level;
     * each department carries a nested `children` array recursively.
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
