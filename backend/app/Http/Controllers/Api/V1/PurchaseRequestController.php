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
     * Return a paginated list of purchase requests, with optional filters.
     *
     * Query parameters:
     *   pr_number     — exact or partial PR number match
     *   department_id — filter by department UUID
     *   status        — filter by status value
     *   date_from     — filter submitted_at >= date (Y-m-d)
     *   date_to       — filter submitted_at <= date (Y-m-d)
     *   submitted_by  — filter by submitter UUID
     *   per_page      — results per page (default 20, max 100)
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
     * Create a new purchase request in draft status.
     *
     * Roles: Department_Staff and above.
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
     * Return a single purchase request with items, history, and department.
     *
     * Tenant scope enforced via route model binding — returns 404 for
     * PRs belonging to a different tenant.
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
     * Submit a purchase request for approval.
     *
     * Returns HTTP 422 with budget details when BudgetExceededException is thrown,
     * including available_balance and shortfall in the response data.
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
     * Cancel a purchase request.
     *
     * Request body (optional):
     *   reason — cancellation reason (string)
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
     * Upload and attach a document to a purchase request.
     *
     * The file is stored at: {tenant_id}/purchase_requests/{uuid}.{ext}
     * A non-guessable UUID is used for the stored filename to prevent enumeration.
     *
     * Returns HTTP 201 with file metadata on success.
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
     * Soft-delete a purchase request (draft status only).
     *
     * Returns HTTP 204 on success.
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
