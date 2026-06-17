<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InventoryResource;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * InventoryController — read-only inventory search and retrieval.
 *
 * Endpoints:
 *   GET /api/v1/inventory          — paginated, filterable inventory list
 *   GET /api/v1/inventory/{id}     — single inventory record
 *
 * Requirements: 12.7, 12.8, 12.9
 */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/inventory
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of inventory items.
     *
     * Query parameters:
     *   warehouse_id  — filter by warehouse UUID
     *   item_code     — partial match on item code
     *   category      — exact match on category
     *   stock_level   — 'below_reorder' or 'above_reorder'
     *   per_page      — results per page (default 20, max 100)
     *
     * Requirements: 12.8
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'warehouse_id'  => $request->query('warehouse_id'),
            'item_code'     => $request->query('item_code'),
            'category'      => $request->query('category'),
            'stock_level'   => $request->query('stock_level'),
            'below_reorder' => $request->has('below_reorder')
                ? filter_var($request->query('below_reorder'), FILTER_VALIDATE_BOOLEAN)
                : null,
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      InventoryResource::collection($paginator->items()),
            message:   'Inventory retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/inventory/{inventory}
    // -------------------------------------------------------------------------

    /**
     * Return a single inventory record.
     *
     * Tenant scope enforced via route model binding.
     *
     * Requirements: 12.8
     */
    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load(['warehouse']);

        return $this->success(
            data:    new InventoryResource($inventory),
            message: 'Inventory item retrieved successfully.',
        );
    }
}
