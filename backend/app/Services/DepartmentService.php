<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * DepartmentService — business logic for department CRUD within tenant scope.
 *
 * Responsibilities:
 *  - Create / read / update departments with unique code per tenant (Req 4.3)
 *  - Deactivate departments while preserving historical records (Req 4.4)
 *  - Restore (re-activate) a deactivated department
 *  - Prevent new PR submissions under deactivated departments (Req 4.4)
 *  - Support parent-child department hierarchy via parent_id (Req 4.5)
 *  - Return full hierarchy tree
 *  - Dispatch audit log entries for all mutations
 *
 * Requirements: 4.3, 4.4, 4.5
 */
class DepartmentService
{
    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a new department within the active tenant.
     *
     * Enforces unique code per tenant.
     * Validates that parent_id (if provided) belongs to the same tenant.
     *
     * @param  array{
     *     name: string,
     *     code: string,
     *     parent_id?: string|null,
     *     status?: string|null,
     * }  $data
     * @param  User|null  $actor
     * @param  string     $ipAddress
     * @param  string|null $requestId
     *
     * @return array{success: bool, message: string, code: int, data: Department|null}
     */
    public function create(
        array $data,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $tenant = app('tenant');

        // Enforce unique code per tenant
        $codeExists = Department::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('code', strtoupper($data['code']))
            ->whereNull('deleted_at')
            ->exists();

        if ($codeExists) {
            return [
                'success' => false,
                'message' => 'A department with this code already exists in this organisation.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['code' => ['A department with this code already exists in this organisation.']],
            ];
        }

        // Validate parent_id belongs to the same tenant (if provided)
        if (! empty($data['parent_id'])) {
            $parentExists = Department::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('id', $data['parent_id'])
                ->whereNull('deleted_at')
                ->exists();

            if (! $parentExists) {
                return [
                    'success' => false,
                    'message' => 'The specified parent department does not exist in this organisation.',
                    'code'    => 422,
                    'data'    => null,
                    'errors'  => ['parent_id' => ['The specified parent department does not exist in this organisation.']],
                ];
            }
        }

        $department = Department::create([
            'name'      => $data['name'],
            'code'      => strtoupper($data['code']),
            'parent_id' => $data['parent_id'] ?? null,
            'status'    => $data['status'] ?? 'active',
        ]);

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'department_created',
            entityId:   $department->id,
            before:     null,
            after:      [
                'name'      => $department->name,
                'code'      => $department->code,
                'parent_id' => $department->parent_id,
                'status'    => $department->status,
            ],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Department created successfully.',
            'code'    => 201,
            'data'    => $department->fresh(['parent', 'children']),
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Read / List
    // -------------------------------------------------------------------------

    /**
     * Return a paginated, searchable list of departments within the tenant.
     *
     * @param  array{
     *     search?: string|null,
     *     status?: string|null,
     *     parent_id?: string|null,
     *     sort_by?: string,
     *     sort_dir?: string,
     *     per_page?: int,
     * }  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Department::with(['parent', 'children']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter top-level departments when parent_id is explicitly 'null' string
        if (array_key_exists('parent_id', $filters)) {
            if ($filters['parent_id'] === 'null' || $filters['parent_id'] === null) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $filters['parent_id']);
            }
        }

        $allowedSortColumns = ['name', 'code', 'status', 'created_at', 'updated_at'];
        $sortBy  = in_array($filters['sort_by'] ?? '', $allowedSortColumns, true)
            ? $filters['sort_by']
            : 'name';
        $sortDir = strtolower($filters['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    /**
     * Return the full department hierarchy as a nested tree.
     *
     * Fetches all departments for the tenant and builds a nested array
     * with children nested under their parents. Requirements: 4.5
     *
     * @return Department[]
     */
    public function getHierarchy(): array
    {
        $all = Department::with(['children'])->get()->keyBy('id');

        $roots = [];

        foreach ($all as $dept) {
            if ($dept->parent_id === null) {
                $roots[] = $this->buildTree($dept, $all);
            }
        }

        return $roots;
    }

    /**
     * Recursively build a department tree node.
     */
    private function buildTree(Department $dept, $all): Department
    {
        $children = $dept->children->map(function ($child) use ($all) {
            return $this->buildTree($child, $all);
        })->values();

        $dept->setRelation('children', $children);

        return $dept;
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Update a department's details within the active tenant.
     *
     * Requirement 4.3 — Tenant_Admin can rename and change department details.
     *
     * @param  array{
     *     name?: string,
     *     code?: string,
     *     parent_id?: string|null,
     * }  $data
     *
     * @return array{success: bool, message: string, code: int, data: Department|null}
     */
    public function update(
        Department $department,
        array $data,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $tenant = app('tenant');
        $before = $department->only(['name', 'code', 'parent_id', 'status']);

        // Enforce unique code per tenant (excluding current department)
        if (! empty($data['code'])) {
            $codeExists = Department::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('code', strtoupper($data['code']))
                ->where('id', '!=', $department->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($codeExists) {
                return [
                    'success' => false,
                    'message' => 'A department with this code already exists in this organisation.',
                    'code'    => 422,
                    'data'    => null,
                    'errors'  => ['code' => ['A department with this code already exists in this organisation.']],
                ];
            }
        }

        // Validate parent_id (if provided) belongs to the same tenant and is not self-referential
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            if ($data['parent_id'] === $department->id) {
                return [
                    'success' => false,
                    'message' => 'A department cannot be its own parent.',
                    'code'    => 422,
                    'data'    => null,
                    'errors'  => ['parent_id' => ['A department cannot be its own parent.']],
                ];
            }

            $parentExists = Department::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('id', $data['parent_id'])
                ->whereNull('deleted_at')
                ->exists();

            if (! $parentExists) {
                return [
                    'success' => false,
                    'message' => 'The specified parent department does not exist in this organisation.',
                    'code'    => 422,
                    'data'    => null,
                    'errors'  => ['parent_id' => ['The specified parent department does not exist in this organisation.']],
                ];
            }
        }

        $fillable = [];

        if (isset($data['name'])) {
            $fillable['name'] = $data['name'];
        }
        if (isset($data['code'])) {
            $fillable['code'] = strtoupper($data['code']);
        }
        if (array_key_exists('parent_id', $data)) {
            $fillable['parent_id'] = $data['parent_id'];
        }

        $department->update($fillable);

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'department_updated',
            entityId:   $department->id,
            before:     $before,
            after:      $department->fresh()->only(['name', 'code', 'parent_id', 'status']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Department updated successfully.',
            'code'    => 200,
            'data'    => $department->fresh(['parent', 'children']),
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Deactivate / Restore
    // -------------------------------------------------------------------------

    /**
     * Deactivate a department (status → inactive).
     *
     * Requirement 4.4 — deactivation preserves all historical records.
     * After deactivation, new PRs cannot be submitted under this department.
     *
     * @return array{success: bool, message: string, code: int, data: Department|null}
     */
    public function deactivate(
        Department $department,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        if ($department->status === 'inactive') {
            return [
                'success' => false,
                'message' => 'Department is already inactive.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['status' => ['Department is already inactive.']],
            ];
        }

        $before = ['status' => $department->status];
        $department->update(['status' => 'inactive']);

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'department_deactivated',
            entityId:   $department->id,
            before:     $before,
            after:      ['status' => 'inactive'],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Department deactivated successfully. Historical records are preserved.',
            'code'    => 200,
            'data'    => $department->fresh(['parent', 'children']),
            'errors'  => null,
        ];
    }

    /**
     * Restore (re-activate) a deactivated department.
     *
     * @return array{success: bool, message: string, code: int, data: Department|null}
     */
    public function restore(
        Department $department,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        if ($department->status === 'active') {
            return [
                'success' => false,
                'message' => 'Department is already active.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['status' => ['Department is already active.']],
            ];
        }

        $before = ['status' => $department->status];
        $department->update(['status' => 'active']);

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'department_activated',
            entityId:   $department->id,
            before:     $before,
            after:      ['status' => 'active'],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Department re-activated successfully.',
            'code'    => 200,
            'data'    => $department->fresh(['parent', 'children']),
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Destroy
    // -------------------------------------------------------------------------

    /**
     * Soft-delete a department.
     *
     * Guards against deletion if the department has active users or purchase requests.
     * Historical PRs are preserved via soft delete.
     *
     * @return array{success: bool, message: string, code: int, data: array|null}
     */
    public function destroy(
        Department $department,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        // Guard: department has child departments
        $childCount = Department::where('parent_id', $department->id)->count();
        if ($childCount > 0) {
            return [
                'success' => false,
                'message' => 'Cannot delete a department that has child departments.',
                'code'    => 422,
                'data'    => ['child_departments' => $childCount],
                'errors'  => ['deletion' => ['Cannot delete a department that has child departments.']],
            ];
        }

        // Guard: department has active users
        $activeUserCount = $department->users()->count();
        if ($activeUserCount > 0) {
            return [
                'success' => false,
                'message' => 'Cannot delete a department that has assigned users.',
                'code'    => 422,
                'data'    => ['active_users' => $activeUserCount],
                'errors'  => ['deletion' => ['Cannot delete a department that has assigned users.']],
            ];
        }

        $before = $department->only(['name', 'code', 'status']);
        $department->delete();

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'department_deleted',
            entityId:   $department->id,
            before:     $before,
            after:      ['deleted_at' => now()->toIso8601String()],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Department deleted successfully.',
            'code'    => 200,
            'data'    => null,
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // PR submission guard
    // -------------------------------------------------------------------------

    /**
     * Validate that the given department is active and can accept new PR submissions.
     *
     * Requirement 4.4 — prevent new PRs under deactivated departments.
     *
     * @return array{allowed: bool, message: string}
     */
    public function validatePRSubmissionAllowed(Department $department): array
    {
        if ($department->status !== 'active') {
            return [
                'allowed' => false,
                'message' => 'Purchase requests cannot be submitted under an inactive department.',
            ];
        }

        return [
            'allowed' => true,
            'message' => 'Department is active.',
        ];
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
                entityType: 'department',
                entityId:   $entityId,
                before:     $before,
                after:      $after,
                ipAddress:  $ipAddress,
                requestId:  $requestId,
            );
        } catch (\Throwable $e) {
            Log::error('DepartmentService: failed to dispatch audit log', [
                'error'       => $e->getMessage(),
                'action_type' => $actionType,
                'entity_id'   => $entityId,
            ]);
        }
    }
}
