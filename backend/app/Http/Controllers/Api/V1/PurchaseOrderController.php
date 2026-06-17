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
     * Return a paginated list of purchase orders, with optional filters.
     *
     * Query parameters:
     *   status       — filter by status value
     *   supplier_id  — filter by supplier UUID
     *   date_from    — filter created_at >= date (Y-m-d)
     *   date_to      — filter created_at <= date (Y-m-d)
     *   po_number    — partial PO number match
     *   per_page     — results per page (default 20, max 100)
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
     * Create a new purchase order in draft status and encumber budget.
     *
     * Roles: Procurement_Officer and above.
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
     * Return a single purchase order with items and supplier details.
     *
     * Tenant scope enforced via route model binding — returns 404 for
     * POs belonging to a different tenant.
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
     * Amend a purchase order.
     *
     * Pre-acceptance (draft/issued): free amendment.
     * Post-acceptance (accepted): sets pending_supplier_acknowledgment = true.
     * Rejected/cancelled/completed: not allowed (422).
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
     * Issue a purchase order to the supplier (draft → issued).
     *
     * Roles: Procurement_Officer and above.
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
     * Accept a purchase order (issued → accepted).
     *
     * Typically called by the Supplier role (or Procurement_Officer on behalf).
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
     * Reject a purchase order (issued → rejected).
     *
     * Releases the budget encumbrance. A rejection reason is required.
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
     * Cancel a purchase order.
     *
     * Allowed from draft, issued, and accepted statuses.
     * Releases the budget encumbrance. A cancellation reason is required.
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
