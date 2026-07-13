<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\PurchaseOrder\AmendPurchaseOrderRequest;
use App\Http\Requests\V1\PurchaseOrder\CancelPurchaseOrderRequest;
use App\Http\Requests\V1\PurchaseOrder\RejectPurchaseOrderRequest;
use App\Http\Requests\V1\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Resources\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * @OA\Tag(name="Purchase Orders", description="Purchase order creation, issuance, acceptance, rejection, amendment, and cancellation.")
 *
 * PurchaseOrderController — thin controller for the PO lifecycle.
 *
 * Endpoints:
 *   GET    /api/v1/purchase-orders                               — paginated list with filters
 *   POST   /api/v1/purchase-orders                              — create PO (draft)
 *   GET    /api/v1/purchase-orders/{purchaseOrder}              — single PO with items and supplier
 *   PUT    /api/v1/purchase-orders/{purchaseOrder}              — amend PO
 *   POST   /api/v1/purchase-orders/{purchaseOrder}/issue        — issue PO to supplier
 *   POST   /api/v1/purchase-orders/{purchaseOrder}/accept       — supplier accepts PO
 *   POST   /api/v1/purchase-orders/{purchaseOrder}/reject       — supplier rejects PO
 *   POST   /api/v1/purchase-orders/{purchaseOrder}/cancel       — cancel PO
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding returns HTTP 404 when the PO belongs to a different tenant.
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.8, 10.9, 10.10
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/purchase-orders
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(
     *     path="/purchase-orders",
     *     operationId="listPurchaseOrders",
     *     tags={"Purchase Orders"},
     *     summary="List purchase orders",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft","issued","accepted","rejected","partially_received","fully_received","cancelled","overdue"})),
     *     @OA\Parameter(name="supplier_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="po_number", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Purchase orders list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/PurchaseOrderResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of purchase orders, with optional filters.
     *
     * Requirements: 10.1
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'status'      => $request->query('status'),
            'supplier_id' => $request->query('supplier_id'),
            'date_from'   => $request->query('date_from'),
            'date_to'     => $request->query('date_to'),
            'po_number'   => $request->query('po_number'),
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      PurchaseOrderResource::collection($paginator->items()),
            message:   'Purchase orders retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-orders
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/purchase-orders",
     *     operationId="createPurchaseOrder",
     *     tags={"Purchase Orders"},
     *     summary="Create purchase order",
     *     description="Creates PO with unique PO number in format PO-{TENANT_CODE}-{YEAR}-{SEQ} and encumbers budget.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"supplier_id","department_id","delivery_address","required_delivery_date","items"}, @OA\Property(property="supplier_id", type="string", format="uuid"), @OA\Property(property="department_id", type="string", format="uuid"), @OA\Property(property="purchase_request_id", type="string", format="uuid", nullable=true), @OA\Property(property="bid_id", type="string", format="uuid", nullable=true), @OA\Property(property="delivery_address", type="string"), @OA\Property(property="required_delivery_date", type="string", format="date"), @OA\Property(property="currency", type="string", example="USD"), @OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="description", type="string"), @OA\Property(property="quantity", type="number"), @OA\Property(property="unit_of_measure", type="string"), @OA\Property(property="unit_price", type="number"))))),
     *     @OA\Response(response=201, description="PO created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseOrderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Create a new purchase order in draft status and encumber budget.
     *
     * Requirements: 10.1, 10.2
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $po = $this->service->generate($request->validated(), $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new PurchaseOrderResource($po),
            message: 'Purchase order created successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/purchase-orders/{purchaseOrder}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(
     *     path="/purchase-orders/{purchaseOrder}",
     *     operationId="showPurchaseOrder",
     *     tags={"Purchase Orders"},
     *     summary="Get purchase order",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseOrder", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="PO with items and supplier.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseOrderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single purchase order with items and supplier details.
     *
     * Requirements: 10.2
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['items', 'supplier', 'department', 'createdBy']);

        return $this->success(
            data:    new PurchaseOrderResource($purchaseOrder),
            message: 'Purchase order retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/purchase-orders/{purchaseOrder}
    // -------------------------------------------------------------------------

    /**
     * @OA\Put(
     *     path="/purchase-orders/{purchaseOrder}",
     *     operationId="amendPurchaseOrder",
     *     tags={"Purchase Orders"},
     *     summary="Amend purchase order",
     *     description="Pre-acceptance: free amendment. Post-acceptance: sets pending supplier acknowledgment.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseOrder", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="delivery_address", type="string"), @OA\Property(property="required_delivery_date", type="string", format="date"), @OA\Property(property="items", type="array", @OA\Items(type="object")))),
     *     @OA\Response(response=200, description="PO amended.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseOrderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Cannot amend in current state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Amend a purchase order.
     *
     * Requirements: 10.9
     */
    public function update(AmendPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $this->service->amend($purchaseOrder, $request->validated(), $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        $purchaseOrder->load(['items', 'supplier', 'department', 'createdBy']);

        return $this->success(
            data:    new PurchaseOrderResource($purchaseOrder->fresh(['items', 'supplier', 'department', 'createdBy'])),
            message: 'Purchase order amended successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-orders/{purchaseOrder}/issue
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/purchase-orders/{purchaseOrder}/issue",
     *     operationId="issuePurchaseOrder",
     *     tags={"Purchase Orders"},
     *     summary="Issue purchase order to supplier",
     *     description="Transitions PO from draft to issued and sends PO to supplier.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseOrder", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="PO issued.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseOrderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="PO not in draft status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Issue a purchase order to the supplier (draft → issued).
     *
     * Requirements: 10.3
     */
    public function issue(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $this->service->issue($purchaseOrder, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PurchaseOrderResource($purchaseOrder->fresh(['items', 'supplier', 'department', 'createdBy'])),
            message: 'Purchase order issued to supplier successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-orders/{purchaseOrder}/accept
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/purchase-orders/{purchaseOrder}/accept",
     *     operationId="acceptPurchaseOrder",
     *     tags={"Purchase Orders"},
     *     summary="Accept purchase order",
     *     description="Supplier accepts the PO (issued → accepted).",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseOrder", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="PO accepted.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseOrderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="PO not in issued status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Accept a purchase order (issued → accepted).
     *
     * Requirements: 10.4
     */
    public function accept(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $this->service->accept($purchaseOrder, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PurchaseOrderResource($purchaseOrder->fresh(['items', 'supplier', 'department', 'createdBy'])),
            message: 'Purchase order accepted successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-orders/{purchaseOrder}/reject
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/purchase-orders/{purchaseOrder}/reject",
     *     operationId="rejectPurchaseOrder",
     *     tags={"Purchase Orders"},
     *     summary="Reject purchase order",
     *     description="Supplier rejects the PO (issued → rejected). Releases budget encumbrance.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseOrder", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Unable to meet delivery terms."))),
     *     @OA\Response(response=200, description="PO rejected.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseOrderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="PO not in issued status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Reject a purchase order (issued → rejected).
     *
     * Requirements: 10.5
     */
    public function reject(RejectPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $reason = $request->validated()['reason'];

        try {
            $this->service->reject($purchaseOrder, $reason, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PurchaseOrderResource($purchaseOrder->fresh(['items', 'supplier', 'department', 'createdBy'])),
            message: 'Purchase order rejected successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/purchase-orders/{purchaseOrder}/cancel
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/purchase-orders/{purchaseOrder}/cancel",
     *     operationId="cancelPurchaseOrder",
     *     tags={"Purchase Orders"},
     *     summary="Cancel purchase order",
     *     description="Cancels PO from draft, issued, or accepted status. Releases budget encumbrance.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="purchaseOrder", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Procurement requirements have changed."))),
     *     @OA\Response(response=200, description="PO cancelled.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PurchaseOrderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Cannot cancel in current state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Cancel a purchase order.
     *
     * Requirements: 10.10
     */
    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $reason = $request->validated()['reason'];

        try {
            $this->service->cancel($purchaseOrder, $reason, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PurchaseOrderResource($purchaseOrder->fresh(['items', 'supplier', 'department', 'createdBy'])),
            message: 'Purchase order cancelled successfully.',
        );
    }
}
