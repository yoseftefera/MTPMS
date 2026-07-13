<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UnauthorizedTenantAccessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Approval\ApproveRequest;
use App\Http\Requests\V1\Approval\RejectRequest;
use App\Http\Requests\V1\Approval\ReturnForRevisionRequest;
use App\Http\Resources\V1\ApprovalResource;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Tender;
use App\Services\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ApprovalController — approval action endpoints for authenticated approvers.
 * @OA\Tag(name="Approvals", description="Approval actions — approve, reject, return for revision. Pending approvals and history.")
 *
 * Endpoints:              — approve a document at current level
 *   POST /api/v1/approvals/{id}/reject               — reject a document (mandatory reason)
 *   POST /api/v1/approvals/{id}/return               — return for revision (mandatory comments)
 *   GET  /api/v1/approvals/pending                   — list user's pending approvals
 *   GET  /api/v1/approvals/history/{type}/{documentId} — full approval history for a document
 *
 * All action endpoints enforce authorisation via canAct() and return HTTP 403
 * when the approver is not designated to act.
 *
 * Requirements: 6.3, 6.4, 6.5, 6.6, 6.7, 6.8
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalWorkflowService $service) {}

    // -------------------------------------------------------------------------
    // POST /api/v1/approvals/{id}/approve
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/approvals/{approval}/approve", operationId="approveDocument", tags={"Approvals"}, summary="Approve document at current level",
     *     description="Advances the document to the next approval level or marks it approved if on the final level.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approval", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=false, @OA\JsonContent(@OA\Property(property="comment", type="string", nullable=true, example="Approved. Budget confirmed."))),
     *     @OA\Response(response=200, description="Approved — document advanced or fully approved.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Not authorised to act on this approval.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Invalid state transition.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Approve a document at the current approval level.
     *
     * Requirements: 6.3, 6.6, 6.7
     */
    public function approve(ApproveRequest $request, Approval $approval): JsonResponse
    {
        $approver = Auth::guard('api')->user();

        // Enforce authorisation before delegating to service
        if (! $this->service->canAct($approval, $approver)) {
            return $this->error(
                message: 'You are not authorised to act on this approval.',
                status:  403,
                errors:  ['approval' => ['You are not authorised to act on this approval.']],
            );
        }

        $originatorId = $this->resolveOriginatorId($approval->document_type, $approval->document_id);

        try {
            $result = $this->service->advance(
                documentType: $approval->document_type,
                documentId:   $approval->document_id,
                approver:     $approver,
                comment:      $request->input('comment'),
                originatorId: $originatorId,
                actor:        $approver,
                ipAddress:    $request->ip() ?? '0.0.0.0',
                requestId:    $request->header('X-Request-ID'),
            );
        } catch (UnauthorizedTenantAccessException $e) {
            return $this->error(
                message: $e->getMessage(),
                status:  403,
                errors:  ['approval' => [$e->getMessage()]],
            );
        }

        if (! $result['success']) {
            $status = $result['code'] === 403 ? 403 : ($result['code'] ?? 422);

            return $this->error(
                message: $result['message'],
                status:  $status,
                errors:  $result['errors'] ?? null,
            );
        }

        return $this->success(
            data:    $result['data'],
            message: $result['message'],
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/approvals/{id}/reject
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/approvals/{approval}/reject", operationId="rejectDocument", tags={"Approvals"}, summary="Reject document",
     *     description="Rejects the document at the current level. Mandatory rejection reason. Notifies the originator.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approval", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Budget allocation insufficient for this fiscal year."))),
     *     @OA\Response(response=200, description="Document rejected.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Not authorised.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Reject a document at the current approval level.
     *
     * The rejection reason is mandatory (validated in RejectRequest).
     *
     * Requirements: 6.4, 6.6, 6.7
     */
    public function reject(RejectRequest $request, Approval $approval): JsonResponse
    {
        $approver = Auth::guard('api')->user();

        if (! $this->service->canAct($approval, $approver)) {
            return $this->error(
                message: 'You are not authorised to act on this approval.',
                status:  403,
                errors:  ['approval' => ['You are not authorised to act on this approval.']],
            );
        }

        $originatorId = $this->resolveOriginatorId($approval->document_type, $approval->document_id);

        try {
            $result = $this->service->reject(
                documentType: $approval->document_type,
                documentId:   $approval->document_id,
                approver:     $approver,
                reason:       $request->input('reason'),
                originatorId: $originatorId,
                actor:        $approver,
                ipAddress:    $request->ip() ?? '0.0.0.0',
                requestId:    $request->header('X-Request-ID'),
            );
        } catch (UnauthorizedTenantAccessException $e) {
            return $this->error(
                message: $e->getMessage(),
                status:  403,
                errors:  ['approval' => [$e->getMessage()]],
            );
        }

        if (! $result['success']) {
            $status = $result['code'] === 403 ? 403 : ($result['code'] ?? 422);

            return $this->error(
                message: $result['message'],
                status:  $status,
                errors:  $result['errors'] ?? null,
            );
        }

        return $this->success(
            data:    $result['data'],
            message: $result['message'],
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/approvals/{id}/return
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/approvals/{approval}/return", operationId="returnDocumentForRevision", tags={"Approvals"}, summary="Return document for revision",
     *     description="Returns the document to the originator for revision. Mandatory comments required.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="approval", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"comments"}, @OA\Property(property="comments", type="string", example="Please attach supporting quotes from at least 3 vendors."))),
     *     @OA\Response(response=200, description="Document returned for revision.", @OA\JsonContent(ref="#/components/schemas/SuccessResponse")),
     *     @OA\Response(response=403, description="Not authorised.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a document for revision at the current approval level.
     *
     * Revision comments are mandatory (validated in ReturnForRevisionRequest).
     *
     * Requirements: 6.5, 6.6, 6.7
     */
    public function return(ReturnForRevisionRequest $request, Approval $approval): JsonResponse
    {
        $approver = Auth::guard('api')->user();

        if (! $this->service->canAct($approval, $approver)) {
            return $this->error(
                message: 'You are not authorised to act on this approval.',
                status:  403,
                errors:  ['approval' => ['You are not authorised to act on this approval.']],
            );
        }

        $originatorId = $this->resolveOriginatorId($approval->document_type, $approval->document_id);

        try {
            $result = $this->service->returnForRevision(
                documentType: $approval->document_type,
                documentId:   $approval->document_id,
                approver:     $approver,
                comments:     $request->input('comments'),
                originatorId: $originatorId,
                actor:        $approver,
                ipAddress:    $request->ip() ?? '0.0.0.0',
                requestId:    $request->header('X-Request-ID'),
            );
        } catch (UnauthorizedTenantAccessException $e) {
            return $this->error(
                message: $e->getMessage(),
                status:  403,
                errors:  ['approval' => [$e->getMessage()]],
            );
        }

        if (! $result['success']) {
            $status = $result['code'] === 403 ? 403 : ($result['code'] ?? 422);

            return $this->error(
                message: $result['message'],
                status:  $status,
                errors:  $result['errors'] ?? null,
            );
        }

        return $this->success(
            data:    $result['data'],
            message: $result['message'],
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/approvals/pending
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/approvals/pending", operationId="listPendingApprovals", tags={"Approvals"}, summary="List pending approvals for authenticated user",
     *     description="Returns all approval records currently pending action from the authenticated user, ordered by creation date ascending.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Pending approvals returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ApprovalResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return all pending approvals for the authenticated user.
     *
     * Requirements: 6.8
     */
    public function pending(Request $request): JsonResponse
    {
        $approver = Auth::guard('api')->user();
        $perPage  = min((int) $request->query('per_page', 20), 100);

        $paginator = Approval::with(['level', 'workflow'])
            ->where('approver_id', $approver->id)
            ->where('action', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      ApprovalResource::collection($paginator->items()),
            message:   'Pending approvals retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/approvals/history/{documentType}/{documentId}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/approvals/history/{documentType}/{documentId}", operationId="approvalHistory", tags={"Approvals"}, summary="Get approval history for a document",
     *     description="Returns the full approval history (all levels and actions) for the specified document.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="documentType", in="path", required=true, description="Document type, e.g. purchase_request, tender, purchase_order, contract, invoice.", @OA\Schema(type="string", enum={"purchase_request","tender","purchase_order","contract","invoice"})),
     *     @OA\Parameter(name="documentId", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Approval history returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ApprovalResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return the full approval history for a document across all levels.
     *
     * Requirements: 6.8
     */
    public function history(Request $request, string $documentType, string $documentId): JsonResponse
    {
        $approvals = $this->service->getHistory($documentType, $documentId);

        $approvals->load(['approver', 'level']);

        return $this->success(
            data:    ApprovalResource::collection($approvals),
            message: 'Approval history retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the originator (creator/submitter) ID for the given document.
     *
     * Each document model may use a different column name for the user who
     * created or submitted it. Falls back to an empty string when not found.
     */
    private function resolveOriginatorId(string $documentType, string $documentId): string
    {
        $document = match ($documentType) {
            'purchase_request' => PurchaseRequest::find($documentId),
            'tender'           => Tender::find($documentId),
            'purchase_order'   => PurchaseOrder::find($documentId),
            'contract'         => Contract::find($documentId),
            'invoice'          => Invoice::find($documentId),
            default            => null,
        };

        if (! $document) {
            return '';
        }

        // Prefer submitted_by, fall back to created_by, then tenant-scoped user_id
        return (string) ($document->submitted_by
            ?? $document->created_by
            ?? '');
    }
}
