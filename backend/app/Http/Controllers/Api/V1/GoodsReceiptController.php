<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\GoodsReceipt\AssignCommitteeRequest;
use App\Http\Requests\V1\GoodsReceipt\StoreGoodsReceiptRequest;
use App\Http\Requests\V1\GoodsReceipt\SubmitInspectionResultRequest;
use App\Http\Resources\V1\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Services\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * GoodsReceiptController — thin controller for the GRN lifecycle.
 *
 * Endpoints:
 *   GET    /api/v1/goods-receipts                                   — paginated list
 *   POST   /api/v1/goods-receipts                                   — create GRN
 *   GET    /api/v1/goods-receipts/{goodsReceipt}                    — single GRN
 *   POST   /api/v1/goods-receipts/{goodsReceipt}/assign-committee   — assign inspectors
 *   POST   /api/v1/goods-receipts/{goodsReceipt}/inspection-result  — submit inspector votes
 *
 * Requirements: 12.1, 12.2, 12.3, 12.6, 12.10
 */
class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/goods-receipts
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of goods receipts with optional filters.
     *
     * Query parameters:
     *   status            — filter by GRN status
     *   purchase_order_id — filter by PO UUID
     *   per_page          — results per page (default 20, max 100)
     *
     * Requirements: 12.1
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'status'            => $request->query('status'),
            'purchase_order_id' => $request->query('purchase_order_id'),
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      GoodsReceiptResource::collection($paginator->items()),
            message:   'Goods receipts retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/goods-receipts
    // -------------------------------------------------------------------------

    /**
     * Create a new Goods Receipt Note in pending_inspection status.
     *
     * Roles: Store_Manager
     *
     * Requirements: 12.1, 12.10
     */
    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $grn = $this->service->create($request->validated(), $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new GoodsReceiptResource($grn),
            message: 'Goods receipt created successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/goods-receipts/{goodsReceipt}
    // -------------------------------------------------------------------------

    /**
     * Return a single GRN with all related data.
     *
     * Requirements: 12.1
     */
    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $goodsReceipt->load(['items', 'purchaseOrder', 'warehouse', 'receivedBy']);

        return $this->success(
            data:    new GoodsReceiptResource($goodsReceipt),
            message: 'Goods receipt retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/goods-receipts/{goodsReceipt}/assign-committee
    // -------------------------------------------------------------------------

    /**
     * Assign ≥2 Committee_Members to inspect this GRN.
     *
     * Transitions status: pending_inspection → under_inspection.
     *
     * Roles: Store_Manager
     *
     * Requirements: 12.2
     */
    public function assignCommittee(AssignCommitteeRequest $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $this->service->assignCommittee(
                $goodsReceipt,
                $request->validated()['committee_user_ids'],
                $user,
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new GoodsReceiptResource($goodsReceipt->fresh(['items', 'purchaseOrder', 'warehouse', 'receivedBy'])),
            message: 'Committee assigned and inspection started.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/goods-receipts/{goodsReceipt}/inspection-result
    // -------------------------------------------------------------------------

    /**
     * Submit one inspector's votes for all GRN line items.
     *
     * Once all assigned inspectors have submitted, inspection is finalized
     * automatically (majority vote applied).
     *
     * Roles: Committee_Member
     *
     * Requirements: 12.3, 12.10
     */
    public function submitInspectionResult(
        SubmitInspectionResultRequest $request,
        GoodsReceipt $goodsReceipt,
    ): JsonResponse {
        $user      = Auth::guard('api')->user();
        $validated = $request->validated();

        try {
            $this->service->submitInspectionResult(
                $goodsReceipt,
                $validated['inspector_id'],
                $validated['results'],
                $user,
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new GoodsReceiptResource($goodsReceipt->fresh(['items', 'purchaseOrder', 'warehouse', 'receivedBy'])),
            message: 'Inspection result submitted successfully.',
        );
    }
}
