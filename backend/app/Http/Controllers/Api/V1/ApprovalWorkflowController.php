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
 * @OA\Tag(name="Approval Workflows", description="Configure multi-level approval chains for PRs, Tenders, POs, Contracts, and Invoices.")
 *
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
     * @OA\Get(
     *     path="/approval-workflows",
     *     operationId="listApprovalWorkflows",
     *     tags={"Approval Workflows"},
     *     summary="List approval workflows",
     *     description="Returns a paginated list of approval workflow configurations for the active tenant.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="document_type", in="query", required=false, @OA\Schema(type="string", enum={"purchase_request","tender","purchase_order","contract","invoice"})),
     *     @OA\Parameter(name="is_active", in="query", required=false, @OA\Schema(type="integer", enum={0,1})),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(
     *         response=200,
     *         description="Workflows list returned.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ApprovalWorkflowResource")),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", nullable=true, example=null),
     *             @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Post(
     *     path="/approval-workflows",
     *     operationId="createApprovalWorkflow",
     *     tags={"Approval Workflows"},
     *     summary="Create approval workflow",
     *     description="Creates a new approval workflow with its initial levels. Levels are sequential approval stages (1–10 max).",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","document_type","levels"},
     *             @OA\Property(property="name", type="string", example="Standard PR Approval"),
     *             @OA\Property(property="document_type", type="string", enum={"purchase_request","tender","purchase_order","contract","invoice"}),
     *             @OA\Property(property="department_id", type="string", format="uuid", nullable=true),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="levels", type="array", @OA\Items(
     *                 @OA\Property(property="level_order", type="integer", example=1),
     *                 @OA\Property(property="approver_type", type="string", enum={"role","user"}),
     *                 @OA\Property(property="approver_role", type="string", nullable=true, example="Finance_Officer"),
     *                 @OA\Property(property="approver_user_id", type="string", format="uuid", nullable=true),
     *                 @OA\Property(property="is_parallel", type="boolean", example=false),
     *                 @OA\Property(property="escalation_hours", type="integer", example=48)
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Workflow created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ApprovalWorkflowResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Get(
     *     path="/approval-workflows/{approvalWorkflow}",
     *     operationId="showApprovalWorkflow",
     *     tags={"Approval Workflows"},
     *     summary="Get approval workflow",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approvalWorkflow", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Workflow returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ApprovalWorkflowResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Put(
     *     path="/approval-workflows/{approvalWorkflow}",
     *     operationId="updateApprovalWorkflow",
     *     tags={"Approval Workflows"},
     *     summary="Update approval workflow",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approvalWorkflow", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="department_id", type="string", format="uuid", nullable=true), @OA\Property(property="is_active", type="boolean"))),
     *     @OA\Response(response=200, description="Workflow updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ApprovalWorkflowResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Delete(
     *     path="/approval-workflows/{approvalWorkflow}",
     *     operationId="deleteApprovalWorkflow",
     *     tags={"Approval Workflows"},
     *     summary="Deactivate approval workflow",
     *     description="Sets is_active=false to preserve historical documents. Does not hard-delete.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approvalWorkflow", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Workflow deactivated.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Post(path="/approval-workflows/{approvalWorkflow}/levels", operationId="addApprovalWorkflowLevel", tags={"Approval Workflows"}, summary="Add level to approval workflow",
     *     description="Appends a new sequential level to an existing approval workflow. level_order, approver_type are required.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approvalWorkflow", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"level_order","approver_type"}, @OA\Property(property="level_order", type="integer", example=2), @OA\Property(property="approver_type", type="string", enum={"role","user"}), @OA\Property(property="approver_role", type="string", nullable=true, example="Finance_Officer"), @OA\Property(property="approver_user_id", type="string", format="uuid", nullable=true), @OA\Property(property="is_parallel", type="boolean", example=false), @OA\Property(property="escalation_hours", type="integer", example=48))),
     *     @OA\Response(response=201, description="Level added.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ApprovalWorkflowLevel"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Delete(path="/approval-workflows/{approvalWorkflow}/levels/{levelId}", operationId="removeApprovalWorkflowLevel", tags={"Approval Workflows"}, summary="Remove level from approval workflow",
     *     description="Deletes a specific level from an approval workflow. Returns 404 if the level does not belong to the specified workflow.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approvalWorkflow", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="levelId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Level removed.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=404, description="Level not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
