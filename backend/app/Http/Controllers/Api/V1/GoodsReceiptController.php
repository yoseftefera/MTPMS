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
 * @OA\Tag(name="Goods Receipts", description="Goods receipt note creation, committee inspection workflow, and GRN lifecycle.")
 *
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
     * @OA\Get(path="/goods-receipts", operationId="listGoodsReceipts", tags={"Goods Receipts"}, summary="List goods receipts",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft","under_inspection","accepted","partially_accepted","rejected"})),
     *     @OA\Parameter(name="purchase_order_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Goods receipts list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/GoodsReceiptResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of goods receipts with optional filters.
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
     * @OA\Post(path="/goods-receipts", operationId="createGoodsReceipt", tags={"Goods Receipts"}, summary="Create goods receipt note (GRN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"purchase_order_id","warehouse_id","delivery_note_number","received_at","items"}, @OA\Property(property="purchase_order_id", type="string", format="uuid"), @OA\Property(property="warehouse_id", type="string", format="uuid"), @OA\Property(property="delivery_note_number", type="string", example="DN-2025-001"), @OA\Property(property="received_at", type="string", format="date-time"), @OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="purchase_order_item_id", type="string", format="uuid"), @OA\Property(property="received_quantity", type="number"))))),
     *     @OA\Response(response=201, description="GRN created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/GoodsReceiptResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Create a new Goods Receipt Note in pending_inspection status.
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
     * @OA\Get(path="/goods-receipts/{goodsReceipt}", operationId="showGoodsReceipt", tags={"Goods Receipts"}, summary="Get goods receipt",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="goodsReceipt", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="GRN with related data.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/GoodsReceiptResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Post(path="/goods-receipts/{goodsReceipt}/assign-committee", operationId="assignGRNCommittee", tags={"Goods Receipts"}, summary="Assign inspection committee",
     *     description="Assigns ≥2 Committee_Members to inspect the GRN. Transitions status to under_inspection.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="goodsReceipt", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"committee_user_ids"}, @OA\Property(property="committee_user_ids", type="array", minItems=2, @OA\Items(type="string", format="uuid")))),
     *     @OA\Response(response=200, description="Committee assigned, inspection started.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/GoodsReceiptResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Fewer than 2 committee members or invalid state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Assign ≥2 Committee_Members to inspect this GRN.
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
     * @OA\Post(path="/goods-receipts/{goodsReceipt}/inspection-result", operationId="submitGRNInspectionResult", tags={"Goods Receipts"}, summary="Submit inspection result",
     *     description="Submits one inspector's votes for all GRN line items. Once all assigned inspectors submit, inspection is finalized via majority vote.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="goodsReceipt", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"inspector_id","results"}, @OA\Property(property="inspector_id", type="string", format="uuid"), @OA\Property(property="results", type="array", @OA\Items(@OA\Property(property="item_id", type="string", format="uuid"), @OA\Property(property="accepted", type="boolean"), @OA\Property(property="rejection_reason", type="string", nullable=true))))),
     *     @OA\Response(response=200, description="Inspection result submitted.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/GoodsReceiptResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Inspector not assigned or inspection already finalized.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Submit one inspector's votes for all GRN line items.
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
