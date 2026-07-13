<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InventoryResource;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Inventory", description="Real-time inventory search and retrieval per warehouse.")
 *
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
     * @OA\Get(path="/inventory", operationId="listInventory", tags={"Inventory"}, summary="List inventory items",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="warehouse_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="item_code", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="stock_level", in="query", required=false, @OA\Schema(type="string", enum={"below_reorder","above_reorder"})),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Inventory list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/InventoryResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of inventory items.
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
     * @OA\Get(path="/inventory/{inventory}", operationId="showInventory", tags={"Inventory"}, summary="Get inventory item",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="inventory", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Inventory item returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/InventoryResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single inventory record.
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
