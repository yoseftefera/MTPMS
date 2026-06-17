<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ApprovalWorkflow\AddLevelRequest;
use App\Http\Requests\V1\ApprovalWorkflow\StoreApprovalWorkflowRequest;
use App\Http\Requests\V1\ApprovalWorkflow\UpdateApprovalWorkflowRequest;
use App\Http\Resources\V1\ApprovalWorkflowLevelResource;
use App\Http\Resources\V1\ApprovalWorkflowResource;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ApprovalWorkflowController — CRUD for approval workflow configuration.
 *
 * All endpoints are restricted to Tenant_Admin via route middleware.
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 *
 * Endpoints:
 *   GET    /api/v1/approval-workflows                              — paginated list
 *   POST   /api/v1/approval-workflows                             — create workflow with levels
 *   GET    /api/v1/approval-workflows/{id}                        — show workflow + levels
 *   PUT    /api/v1/approval-workflows/{id}                        — update workflow
 *   DELETE /api/v1/approval-workflows/{id}                        — deactivate/soft-delete
 *   POST   /api/v1/approval-workflows/{id}/levels                 — add a level
 *   DELETE /api/v1/approval-workflows/{id}/levels/{levelId}       — remove a level
 *
 * Requirements: 6.2
 */
class ApprovalWorkflowController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/approval-workflows
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of approval workflows for the active tenant.
     *
     * Query parameters:
     *   document_type — filter by document type
     *   is_active     — filter by active status (1/0)
     *   per_page      — results per page (max 100, default 20)
     *
     * Requirements: 6.2
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = ApprovalWorkflow::with(['levels', 'department']);

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->query('document_type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->query('is_active'));
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      ApprovalWorkflowResource::collection($paginator->items()),
            message:   'Approval workflows retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/approval-workflows
    // -------------------------------------------------------------------------

    /**
     * Create a new approval workflow together with its initial levels.
     *
     * Requirements: 6.2
     */
    public function store(StoreApprovalWorkflowRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $workflow = DB::transaction(function () use ($validated, $request) {
            /** @var ApprovalWorkflow $workflow */
            $workflow = ApprovalWorkflow::create([
                'name'          => $validated['name'],
                'document_type' => $validated['document_type'],
                'department_id' => $validated['department_id'] ?? null,
                'is_active'     => $validated['is_active'] ?? true,
            ]);

            foreach ($validated['levels'] as $levelData) {
                $workflow->levels()->create([
                    'level_order'      => $levelData['level_order'],
                    'approver_type'    => $levelData['approver_type'],
                    'approver_role'    => $levelData['approver_role'] ?? null,
                    'approver_user_id' => $levelData['approver_user_id'] ?? null,
                    'is_parallel'      => $levelData['is_parallel'] ?? false,
                    'escalation_hours' => $levelData['escalation_hours'] ?? 48,
                ]);
            }

            return $workflow->load(['levels', 'department']);
        });

        return $this->created(
            data:    new ApprovalWorkflowResource($workflow),
            message: 'Approval workflow created successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/approval-workflows/{id}
    // -------------------------------------------------------------------------

    /**
     * Return a single approval workflow with its levels.
     *
     * Requirements: 6.2
     */
    public function show(ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $approvalWorkflow->load(['levels', 'department']);

        return $this->success(
            data:    new ApprovalWorkflowResource($approvalWorkflow),
            message: 'Approval workflow retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/approval-workflows/{id}
    // -------------------------------------------------------------------------

    /**
     * Update an approval workflow's name, department, or active status.
     *
     * Note: levels are managed individually via addLevel / removeLevel.
     *
     * Requirements: 6.2
     */
    public function update(UpdateApprovalWorkflowRequest $request, ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $approvalWorkflow->update($request->validated());
        $approvalWorkflow->load(['levels', 'department']);

        return $this->success(
            data:    new ApprovalWorkflowResource($approvalWorkflow),
            message: 'Approval workflow updated successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/approval-workflows/{id}
    // -------------------------------------------------------------------------

    /**
     * Deactivate (soft-delete) an approval workflow by setting is_active = false.
     *
     * Rather than hard-deleting, we deactivate so existing documents using
     * this workflow are not orphaned.
     *
     * Requirements: 6.2
     */
    public function destroy(ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $approvalWorkflow->update(['is_active' => false]);

        return $this->success(
            data:    null,
            message: 'Approval workflow deactivated successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/approval-workflows/{id}/levels
    // -------------------------------------------------------------------------

    /**
     * Add a new level to an existing approval workflow.
     *
     * Requirements: 6.2
     */
    public function addLevel(AddLevelRequest $request, ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $validated = $request->validated();

        $level = $approvalWorkflow->levels()->create([
            'level_order'      => $validated['level_order'],
            'approver_type'    => $validated['approver_type'],
            'approver_role'    => $validated['approver_role'] ?? null,
            'approver_user_id' => $validated['approver_user_id'] ?? null,
            'is_parallel'      => $validated['is_parallel'] ?? false,
            'escalation_hours' => $validated['escalation_hours'] ?? 48,
        ]);

        return $this->created(
            data:    new ApprovalWorkflowLevelResource($level),
            message: 'Approval workflow level added successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/approval-workflows/{id}/levels/{levelId}
    // -------------------------------------------------------------------------

    /**
     * Remove a level from an approval workflow.
     *
     * Returns HTTP 404 when the level does not belong to the specified workflow.
     *
     * Requirements: 6.2
     */
    public function removeLevel(ApprovalWorkflow $approvalWorkflow, string $levelId): JsonResponse
    {
        $level = ApprovalWorkflowLevel::where('workflow_id', $approvalWorkflow->id)
            ->where('id', $levelId)
            ->first();

        if (! $level) {
            return $this->error(
                message: 'Approval workflow level not found.',
                status:  404,
            );
        }

        $level->delete();

        return $this->success(
            data:    null,
            message: 'Approval workflow level removed successfully.',
        );
    }
}
