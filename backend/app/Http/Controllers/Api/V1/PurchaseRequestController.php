<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BudgetExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\PurchaseRequest\AttachDocumentRequest;
use App\Http\Requests\V1\PurchaseRequest\StorePurchaseRequestRequest;
use App\Http\Requests\V1\PurchaseRequest\UpdatePurchaseRequestRequest;
use App\Http\Resources\V1\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @OA\Tag(name="Purchase Requests", description="Purchase request creation, lifecycle, and document attachments.")
 *
 * PurchaseRequestController — thin controller for the PR lifecycle.
 *
 * Endpoints:
 *   GET    /api/v1/purchase-requests                              — paginated list with filters
 *   POST   /api/v1/purchase-requests                             — create PR (draft)
 *   GET    /api/v1/purchase-requests/{purchaseRequest}           — single PR with items, history
 *   PUT    /api/v1/purchase-requests/{purchaseRequest}           — update draft PR
 *   POST   /api/v1/purchase-requests/{purchaseRequest}/submit    — submit for approval
 *   POST   /api/v1/purchase-requests/{purchaseRequest}/cancel    — cancel PR
 *   POST   /api/v1/purchase-requests/{purchaseRequest}/documents — attach a document
 *   DELETE /api/v1/purchase-requests/{purchaseRequest}           — soft-delete draft PR
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding returns HTTP 404 when the PR belongs to a different tenant.
 *
 * Requirements: 5.8, 5.10
 */
class PurchaseRequestController extends Controller
{
    public function __construct(private readonly PurchaseRequestService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/purchase-requests
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/purchase-requests", operationId="listPurchaseRequests", tags={"Purchase Requests"}, summary="List purchase requests",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="pr_number", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft","pending_approval","approved","rejected","revision_required","cancelled"})),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="submitted_by", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Purchase requests list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/PurchaseRequestResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of purchase requests, with optional filters.
     *
     * Requirements: 5.8
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'pr_number'     => $request->query('pr_number'),
            'department_id' => $request->query('department_id'),
            'status'        => $request->query('status'),
            'date_from'     => $request->query('date_from'),
            'date_to'       => $request->query('date_to'),
            'submitted_by'  => $request->query('submitted_by'),
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      PurchaseRequestResource::collection($paginator->items()),
            message:   'Purchase requests retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-requests
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/purchase-requests", operationId="createPurchaseRequest", tags={"Purchase Requests"}, summary="Create purchase request",
     *     description="Creates a new PR in draft status. PR number generated in format PR-{TENANT_CODE}-{YEAR}-{SEQ}.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"title","department_id","items"}, @OA\Property(property="title", type="string", example="Office Supplies Q1 2025"), @OA\Property(property="description", type="string", nullable=true), @OA\Property(property="department_id", type="string", format="uuid"), @OA\Property(property="required_date", type="string", format="date", nullable=true), @OA\Property(property="currency", type="string", example="USD"), @OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="description", type="string"), @OA\Property(property="quantity", type="number"), @OA\Property(property="unit_of_measure", type="string"), @OA\Property(property="estimated_unit_price", type="number"), @OA\Property(property="budget_code", type="string", nullable=true))))),
     *     @OA\Response(response=201, description="PR created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseRequestResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Create a new purchase request in draft status.
     *
     * Requirements: 5.1, 5.2
     */
    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $pr = $this->service->create($request->validated(), $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new PurchaseRequestResource($pr),
            message: 'Purchase request created successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/purchase-requests/{purchaseRequest}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/purchase-requests/{purchaseRequest}", operationId="showPurchaseRequest", tags={"Purchase Requests"}, summary="Get purchase request",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseRequest", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="PR with items and history.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseRequestResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single purchase request with items, history, and department.
     *
     * Requirements: 5.8
     */
    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $purchaseRequest->load(['items', 'history', 'department', 'submittedBy']);

        return $this->success(
            data:    new PurchaseRequestResource($purchaseRequest),
            message: 'Purchase request retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/purchase-requests/{purchaseRequest}
    // -------------------------------------------------------------------------

    /**
     * @OA\Put(path="/purchase-requests/{purchaseRequest}", operationId="updatePurchaseRequest", tags={"Purchase Requests"}, summary="Update draft purchase request",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseRequest", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="title", type="string"), @OA\Property(property="description", type="string", nullable=true), @OA\Property(property="required_date", type="string", format="date", nullable=true), @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/PurchaseRequestItem")))),
     *     @OA\Response(response=200, description="PR updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseRequestResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="PR not in draft status or validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Update a purchase request that is currently in draft status.
     *
     * Requirements: 5.2, 5.5
     */
    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $pr = $this->service->update($purchaseRequest, $request->validated(), $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PurchaseRequestResource($pr),
            message: 'Purchase request updated successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-requests/{purchaseRequest}/submit
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/purchase-requests/{purchaseRequest}/submit", operationId="submitPurchaseRequest", tags={"Purchase Requests"}, summary="Submit PR for approval",
     *     description="Validates budget and transitions PR to pending_approval, triggering the approval workflow.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseRequest", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="PR submitted.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseRequestResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Budget exceeded — includes available_balance and shortfall.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=false), @OA\Property(property="data", type="object", @OA\Property(property="available_balance", type="string", example="10000.00"), @OA\Property(property="shortfall", type="string", example="5000.00")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", type="object"), @OA\Property(property="meta", nullable=true, example=null)))
     * )
     *
     * Submit a purchase request for approval.
     *
     * Requirements: 5.3, 5.4, 5.6
     */
    public function submit(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $pr = $this->service->submit($purchaseRequest, $user);
        } catch (BudgetExceededException $e) {
            return response()->json([
                'success' => false,
                'data'    => [
                    'available_balance' => number_format((float) $e->getAvailableBalance(), 2, '.', ''),
                    'shortfall'         => number_format((float) $e->getShortfall(), 2, '.', ''),
                ],
                'message' => $e->getMessage(),
                'errors'  => [
                    'budget' => [$e->getMessage()],
                ],
                'meta'    => null,
            ], 422);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PurchaseRequestResource($pr),
            message: 'Purchase request submitted for approval successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-requests/{purchaseRequest}/cancel
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/purchase-requests/{purchaseRequest}/cancel", operationId="cancelPurchaseRequest", tags={"Purchase Requests"}, summary="Cancel purchase request",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseRequest", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=false, @OA\JsonContent(@OA\Property(property="reason", type="string", example="No longer required."))),
     *     @OA\Response(response=200, description="PR cancelled.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseRequestResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="PR cannot be cancelled in current state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Cancel a purchase request.
     *
     * Requirements: 5.7
     */
    public function cancel(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $reason = $request->input('reason', '');

        try {
            $pr = $this->service->cancel($purchaseRequest, $user, (string) $reason);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PurchaseRequestResource($pr),
            message: 'Purchase request cancelled successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-requests/{purchaseRequest}/documents
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/purchase-requests/{purchaseRequest}/documents", operationId="attachPRDocument", tags={"Purchase Requests"}, summary="Attach document to PR",
     *     description="Uploads and attaches a document. Allowed types: PDF, DOCX, XLSX, PNG, JPG, JPEG. Max size: 10 MB.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseRequest", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(required={"file"}, @OA\Property(property="file", type="string", format="binary", description="File to attach (max 10 MB).")))),
     *     @OA\Response(response=201, description="Document attached.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="file_name", type="string"), @OA\Property(property="path", type="string"), @OA\Property(property="mime_type", type="string"), @OA\Property(property="size_bytes", type="integer")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid file type or size.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Upload and attach a document to a purchase request.
     *
     * Requirements: 5.10
     */
    public function attachDocument(AttachDocumentRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $file = $request->file('file');

        // Resolve tenant_id from the PR or the authenticated user
        $tenantId  = $purchaseRequest->tenant_id;
        $uuid      = Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension());
        $storedPath = "{$tenantId}/purchase_requests/{$uuid}.{$extension}";

        // Store using the local disk in tenant-scoped directory
        Storage::disk('local')->put($storedPath, file_get_contents($file->getRealPath()));

        $metadata = [
            'file_name'     => $file->getClientOriginalName(),
            'stored_name'   => "{$uuid}.{$extension}",
            'path'          => $storedPath,
            'mime_type'     => $file->getMimeType(),
            'size_bytes'    => $file->getSize(),
            'extension'     => $extension,
            'uploaded_by'   => $user->id,
            'uploaded_at'   => now()->toIso8601String(),
        ];

        return $this->created(
            data:    $metadata,
            message: 'Document attached successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/purchase-requests/{purchaseRequest}
    // -------------------------------------------------------------------------

    /**
     * @OA\Delete(path="/purchase-requests/{purchaseRequest}", operationId="deletePurchaseRequest", tags={"Purchase Requests"}, summary="Delete draft PR",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseRequest", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=204, description="PR deleted (no content).", ),
     *     @OA\Response(response=422, description="PR is not in draft status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Soft-delete a purchase request (draft status only).
     *
     * Requirements: 5.5
     */
    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        if ($purchaseRequest->status !== 'draft') {
            return $this->error(
                message: "Only draft purchase requests may be deleted (current status: {$purchaseRequest->status}).",
                status:  422,
                errors:  ['status' => ['Only draft purchase requests may be deleted.']],
            );
        }

        $purchaseRequest->delete();

        return $this->noContent();
    }
}
