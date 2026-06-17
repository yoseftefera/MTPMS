<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Inventory;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * InventoryService — manages real-time stock balances per item per warehouse.
 *
 * Responsibilities:
 *   - Upsert an Inventory record by (tenant_id, warehouse_id, item_code) when
 *     goods are accepted on a GRN.
 *   - Fire low-stock notifications to Store_Manager users when stock falls below
 *     the configured reorder threshold.
 *   - Provide a filterable, paginated inventory search.
 *
 * Requirements: 12.7, 12.8, 12.9
 */
class InventoryService
{
    /**
     * BCMath decimal scale — matches DECIMAL(15,2) database columns.
     */
    private const SCALE = 2;

    // =========================================================================
    // updateStock
    // =========================================================================

    /**
     * Upsert an inventory record and add the accepted quantity to current_stock.
     *
     * Upserts by (tenant_id, warehouse_id, item_code). When creating a new record
     * the supplied item_name, unit_of_measure, and category are stored; on update
     * only current_stock is modified so that user-maintained values are preserved.
     *
     * If the resulting current_stock falls below reorder_threshold, a low-stock
     * notification is dispatched to all Store_Manager users within the tenant.
     *
     * Requirements: 12.7, 12.9
     *
     * @param  string  $warehouseId    UUID of the destination warehouse
     * @param  string  $itemCode       Item code (from PO item or user-supplied)
     * @param  string  $itemName       Human-readable item name / description
     * @param  string  $quantityAdded  Accepted quantity (BCMath-safe string)
     * @param  string  $unitOfMeasure  Unit of measure (e.g. "kg", "pcs")
     * @param  string  $category       Category name (default: 'General')
     * @param  string  $tenantId       UUID of the active tenant
     * @param  User    $actor          The user triggering the stock update
     */
    public function updateStock(
        string $warehouseId,
        string $itemCode,
        string $itemName,
        string $quantityAdded,
        string $unitOfMeasure = 'unit',
        string $category      = 'General',
        string $tenantId      = '',
        User   $actor         = null,
    ): void {
        // Resolve tenantId from actor when not passed explicitly.
        if ($tenantId === '') {
            $tenantId = $actor?->tenant_id ?? '';
        }

        DB::transaction(function () use ($warehouseId, $itemCode, $itemName, $quantityAdded, $unitOfMeasure, $category, $tenantId, $actor) {
            // Lock the inventory row to prevent race conditions under concurrent GRN finalization.
            /** @var Inventory|null $inventory */
            $inventory = Inventory::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('item_code', $itemCode)
                ->lockForUpdate()
                ->first();

            $oldStock = $inventory ? $this->normalise($inventory->current_stock) : '0.00';
            $newStock  = bcadd($oldStock, $this->normalise($quantityAdded), self::SCALE);

            if ($inventory) {
                $inventory->update(['current_stock' => $newStock]);
            } else {
                // Create a new inventory record using the supplied metadata.
                $inventory = Inventory::withoutGlobalScopes()->create([
                    'tenant_id'         => $tenantId,
                    'warehouse_id'      => $warehouseId,
                    'item_code'         => $itemCode,
                    'item_name'         => $itemName,
                    'category'          => $category,
                    'unit_of_measure'   => $unitOfMeasure,
                    'current_stock'     => $newStock,
                    'reorder_threshold' => '0.00',
                    'unit_cost'         => '0.00',
                ]);
            }

            // Check low-stock threshold and notify if needed.
            $threshold = $this->normalise($inventory->reorder_threshold ?? '0.00');
            $isLow     = bccomp($threshold, '0.00', self::SCALE) > 0
                      && bccomp($newStock, $threshold, self::SCALE) < 0;

            if ($isLow) {
                $this->notifyLowStock($inventory, $newStock, $threshold, $tenantId);
            }

            WriteAuditLogJob::dispatch(
                tenantId:   $tenantId,
                userId:     $actor?->id,
                userRole:   $actor?->getRoleNames()->first() ?? 'store_manager',
                actionType: 'inventory.stock_updated',
                entityType: 'inventory',
                entityId:   $inventory->id,
                before:     ['current_stock' => $oldStock],
                after:      ['current_stock' => $newStock, 'quantity_added' => $quantityAdded],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // search
    // =========================================================================

    /**
     * Return a paginated list of inventory items for the active tenant.
     *
     * Supported filters:
     *   warehouse_id   — filter by warehouse UUID
     *   item_code      — partial match on item_code
     *   category       — exact match on category
     *   below_reorder  — bool: true to return only items where current_stock < reorder_threshold
     *   stock_level    — 'below_reorder' or 'above_reorder' (alternative to bool below_reorder)
     *
     * Requirements: 12.8
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Inventory::with(['warehouse']);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['item_code'])) {
            $query->where('item_code', 'like', '%' . $filters['item_code'] . '%');
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        // Bool filter: below_reorder=true
        if (isset($filters['below_reorder']) && $filters['below_reorder'] === true) {
            $query->whereColumn('current_stock', '<', 'reorder_threshold')
                  ->where('reorder_threshold', '>', 0);
        }

        // String filter for backward compatibility
        if (! empty($filters['stock_level'])) {
            if ($filters['stock_level'] === 'below_reorder') {
                $query->whereColumn('current_stock', '<', 'reorder_threshold')
                      ->where('reorder_threshold', '>', 0);
            } elseif ($filters['stock_level'] === 'above_reorder') {
                $query->whereColumn('current_stock', '>=', 'reorder_threshold');
            }
        }

        return $query->orderBy('item_code')->paginate($perPage);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Normalise a numeric value to a BCMath-safe string with SCALE decimal places.
     */
    private function normalise(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', self::SCALE);
    }

    /**
     * Notify all Store_Manager users in the tenant that an item is below reorder threshold.
     *
     * Requirements: 12.9
     */
    private function notifyLowStock(
        Inventory $inventory,
        string $currentStock,
        string $threshold,
        string $tenantId,
    ): void {
        // Find all active Store_Manager users in this tenant.
        $storeManagers = \App\Models\User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', 'store_manager'))
            ->get();

        foreach ($storeManagers as $manager) {
            try {
                Notification::withoutGlobalScopes()->create([
                    'tenant_id'  => $tenantId,
                    'user_id'    => $manager->id,
                    'event_type' => 'inventory_low_stock',
                    'title'      => "Low Stock Alert: {$inventory->item_code}",
                    'message'    => "Item '{$inventory->item_name}' (code: {$inventory->item_code}) "
                                  . "in warehouse {$inventory->warehouse_id} has fallen below the reorder "
                                  . "threshold. Current stock: {$currentStock}, Threshold: {$threshold}.",
                    'data'       => [
                        'inventory_id'      => $inventory->id,
                        'item_code'         => $inventory->item_code,
                        'item_name'         => $inventory->item_name,
                        'warehouse_id'      => $inventory->warehouse_id,
                        'current_stock'     => $currentStock,
                        'reorder_threshold' => $threshold,
                    ],
                    'is_read' => false,
                ]);
            } catch (\Throwable $e) {
                Log::error('InventoryService: failed to send low-stock notification', [
                    'inventory_id' => $inventory->id,
                    'manager_id'   => $manager->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
    }
}
