<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * GoodsReceiptService — manages the full goods receiving lifecycle.
 *
 * Status flow:
 *   pending_inspection → under_inspection → accepted / partially_accepted / rejected
 *
 * Workflow:
 *   1. create()                — Store_Manager creates a GRN; status: pending_inspection
 *   2. assignCommittee()       — Store_Manager assigns ≥2 Committee_Members; status: under_inspection
 *   3. submitInspectionResult() — Each inspector records per-item accept/reject votes
 *   4. finalizeInspection()    — (private) Majority-vote aggregation; updates PO + inventory
 *
 * GRN number format: GRN-{TENANT_CODE}-{YEAR}-{SEQUENCE}
 *
 * Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 12.10
 */
class GoodsReceiptService
{
    /**
     * BCMath decimal scale — matches DECIMAL(15,2) database columns.
     */
    private const SCALE = 2;

    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    // =========================================================================
    // GRN Number Generation
    // =========================================================================

    /**
     * Generate the next sequential GRN number for the given tenant and year.
     *
     * Format: GRN-{TENANT_CODE}-{YEAR}-{SEQUENCE}  (SEQUENCE zero-padded to 5 digits)
     * Example: GRN-ACME-2024-00001
     *
     * Uses a pessimistic lock to prevent duplicate sequences under concurrent
     * submissions.
     *
     * Requirements: 12.1
     */
    public function generateGRNNumber(string $tenantId, string $tenantCode, int $year = 0): string
    {
        if ($year === 0) {
            $year = now()->year;
        }

        $tenantCode = strtoupper($tenantCode);

        $sequence = DB::transaction(function () use ($tenantId, $year) {
            $count = DB::table('goods_receipts')
                ->where('tenant_id', $tenantId)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();

            return $count + 1;
        });

        return sprintf('GRN-%s-%d-%05d', $tenantCode, $year, $sequence);
    }

    // =========================================================================
    // Create
    // =========================================================================

    /**
     * Create a new Goods Receipt Note in `pending_inspection` status.
     *
     * Required data keys:
     *   purchase_order_id    — UUID of the PO being received against
     *   warehouse_id         — UUID of the receiving warehouse
     *   delivery_note_number — Supplier delivery note reference
     *   items                — array of { po_item_id, received_quantity }
     *
     * Validates that received_quantity does not exceed the outstanding
     * quantity on each PO line item (PO qty − already_received_qty).
     *
     * Requirements: 12.1, 12.10
     *
     * @param  array{
     *     purchase_order_id: string,
     *     warehouse_id: string,
     *     delivery_note_number: string,
     *     items: array<int, array{po_item_id: string, received_quantity: string|int|float}>,
     * }  $data
     *
     * @throws InvalidArgumentException  when required fields are missing or quantities exceed outstanding
     */
    public function create(array $data, User $actor): GoodsReceipt
    {
        $this->validateCreateData($data);

        return DB::transaction(function () use ($data, $actor) {
            $tenant    = $actor->tenant ?? app('tenant');
            $tenantId  = $actor->tenant_id ?? $tenant->id;
            $tenantCode = strtoupper($tenant->tenant_code);

            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::withoutGlobalScopes()
                ->with('items')
                ->where('id', $data['purchase_order_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            // Validate quantities before creating anything
            $this->validateReceivedQuantities($po, $data['items']);

            $grnNumber = $this->generateGRNNumber($tenantId, $tenantCode);

            /** @var GoodsReceipt $grn */
            $grn = GoodsReceipt::create([
                'grn_number'          => $grnNumber,
                'tenant_id'           => $tenantId,
                'purchase_order_id'   => $po->id,
                'warehouse_id'        => $data['warehouse_id'],
                'delivery_note_number'=> $data['delivery_note_number'],
                'status'              => 'pending_inspection',
                'received_by'         => $actor->id,
                'received_at'         => now(),
            ]);

            foreach ($data['items'] as $item) {
                /** @var PurchaseOrderItem $poItem */
                $poItem = $po->items->firstWhere('id', $item['po_item_id']);

                GoodsReceiptItem::create([
                    'tenant_id'              => $tenantId,
                    'goods_receipt_id'       => $grn->id,
                    'purchase_order_item_id' => $poItem->id,
                    'description'            => $poItem->description,
                    'quantity_received'      => $this->normalise($item['received_quantity']),
                    'quantity_accepted'      => '0.00',
                    'quantity_rejected'      => '0.00',
                    'status'                 => 'pending',
                    'inspection_votes'       => null,
                ]);
            }

            WriteAuditLogJob::dispatch(
                tenantId:   $tenantId,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'store_manager',
                actionType: 'goods_receipt.created',
                entityType: 'goods_receipt',
                entityId:   $grn->id,
                before:     null,
                after:      [
                    'grn_number'           => $grnNumber,
                    'status'               => 'pending_inspection',
                    'purchase_order_id'    => $po->id,
                    'delivery_note_number' => $data['delivery_note_number'],
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            return $grn->load(['items', 'purchaseOrder', 'warehouse', 'receivedBy']);
        });
    }

    // =========================================================================
    // Assign Committee
    // =========================================================================

    /**
     * Assign ≥2 Committee_Member users to inspect this GRN.
     *
     * Transitions status from `pending_inspection` → `under_inspection`.
     * Stores the committee user IDs in the `assigned_inspectors` JSON column.
     *
     * Requirements: 12.2
     *
     * @param  list<string>  $committeeUserIds  UUIDs of Committee_Member users
     *
     * @throws InvalidArgumentException  when fewer than 2 inspectors are provided
     * @throws InvalidArgumentException  when GRN is not in `pending_inspection` status
     */
    public function assignCommittee(GoodsReceipt $grn, array $committeeUserIds, User $actor): void
    {
        if (count($committeeUserIds) < 2) {
            throw new InvalidArgumentException(
                'At least 2 Committee_Members must be assigned for goods inspection.'
            );
        }

        if ($grn->status !== 'pending_inspection') {
            throw new InvalidArgumentException(
                "Committee can only be assigned when GRN is in 'pending_inspection' status "
                . "(current status: {$grn->status})."
            );
        }

        DB::transaction(function () use ($grn, $committeeUserIds, $actor) {
            $before = ['status' => $grn->status, 'assigned_inspectors' => null];

            $grn->update([
                'status'               => 'under_inspection',
                'assigned_inspectors'  => array_values(array_unique($committeeUserIds)),
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $grn->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'store_manager',
                actionType: 'goods_receipt.committee_assigned',
                entityType: 'goods_receipt',
                entityId:   $grn->id,
                before:     $before,
                after:      [
                    'status'              => 'under_inspection',
                    'assigned_inspectors' => array_values(array_unique($committeeUserIds)),
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // Submit Inspection Result
    // =========================================================================

    /**
     * Record one inspector's vote for each GRN line item.
     *
     * Each element of $results must contain:
     *   grn_item_id  — UUID of the GoodsReceiptItem
     *   accepted     — bool  (true = inspector accepts the item)
     *   notes        — string (optional)
     *
     * Votes are appended to the `inspection_votes` JSON column on each item.
     * Once ALL assigned inspectors have submitted votes, `finalizeInspection()`
     * is called automatically.
     *
     * Requirements: 12.2, 12.3, 12.10
     *
     * @param  array<int, array{grn_item_id: string, accepted: bool, notes?: string}>  $results
     *
     * @throws InvalidArgumentException  when GRN is not `under_inspection`
     * @throws InvalidArgumentException  when inspector is not in assigned committee
     */
    public function submitInspectionResult(
        GoodsReceipt $grn,
        string $inspectorId,
        array $results,
        User $actor,
    ): void {
        if ($grn->status !== 'under_inspection') {
            throw new InvalidArgumentException(
                "Inspection results can only be submitted when GRN is 'under_inspection' "
                . "(current status: {$grn->status})."
            );
        }

        $assignedInspectors = $grn->assigned_inspectors ?? [];
        if (! in_array($inspectorId, $assignedInspectors, true)) {
            throw new InvalidArgumentException(
                'The specified inspector is not assigned to this GRN committee.'
            );
        }

        DB::transaction(function () use ($grn, $inspectorId, $results, $actor) {
            $grn->load('items');

            foreach ($results as $result) {
                /** @var GoodsReceiptItem|null $item */
                $item = $grn->items->firstWhere('id', $result['grn_item_id']);

                if (! $item) {
                    continue;
                }

                $votes = $item->inspection_votes ?? [];
                $votes[$inspectorId] = [
                    'accepted'  => (bool) $result['accepted'],
                    'notes'     => $result['notes'] ?? null,
                    'voted_at'  => now()->toIso8601String(),
                ];

                $item->update(['inspection_votes' => $votes]);
            }

            WriteAuditLogJob::dispatch(
                tenantId:   $grn->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'committee_member',
                actionType: 'goods_receipt.inspection_submitted',
                entityType: 'goods_receipt',
                entityId:   $grn->id,
                before:     null,
                after:      [
                    'inspector_id'   => $inspectorId,
                    'results_count'  => count($results),
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            // Check if all assigned inspectors have now voted on all items.
            $this->checkAndFinalizeIfComplete($grn->fresh(['items']));
        });
    }

    // =========================================================================
    // Search / List
    // =========================================================================

    /**
     * Return a paginated list of GRNs within the active tenant scope.
     *
     * Supported filters:
     *   status            — filter by GRN status
     *   purchase_order_id — filter by PO UUID
     *
     * Requirements: 12.1
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = GoodsReceipt::with(['purchaseOrder', 'warehouse', 'receivedBy', 'items']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', $filters['purchase_order_id']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // =========================================================================
    // Private: Finalization
    // =========================================================================

    /**
     * Check whether all assigned inspectors have submitted votes on all items.
     * If yes, trigger `finalizeInspection()`.
     */
    private function checkAndFinalizeIfComplete(GoodsReceipt $grn): void
    {
        $assignedInspectors = $grn->assigned_inspectors ?? [];

        if (empty($assignedInspectors)) {
            return;
        }

        foreach ($grn->items as $item) {
            $votes = $item->inspection_votes ?? [];
            foreach ($assignedInspectors as $inspectorId) {
                if (! array_key_exists($inspectorId, $votes)) {
                    // At least one inspector hasn't voted yet — wait.
                    return;
                }
            }
        }

        // All inspectors have voted on all items — finalize.
        $this->finalizeInspection($grn);
    }

    /**
     * Apply majority-vote logic, update item statuses, update PO received quantities,
     * update inventory, and set the final GRN status.
     *
     * Majority vote per item: if > 50% of inspectors accepted → item accepted.
     * Calls InventoryService::updateStock() for each accepted item.
     * Notifies the Store_Manager (GRN created_by user).
     *
     * Requirements: 12.3, 12.4, 12.5, 12.6, 12.7
     */
    protected function finalizeInspection(GoodsReceipt $grn): void
    {
        $grn->load(['items.purchaseOrderItem', 'purchaseOrder.items']);

        $assignedInspectors = $grn->assigned_inspectors ?? [];
        $inspectorCount     = count($assignedInspectors);
        $anyAccepted        = false;
        $allRejected        = true;

        foreach ($grn->items as $item) {
            $votes         = $item->inspection_votes ?? [];
            $acceptedVotes = 0;

            foreach ($votes as $vote) {
                if ((bool) $vote['accepted']) {
                    $acceptedVotes++;
                }
            }

            // Majority = strictly more than 50 %
            $majorityAccepted = $inspectorCount > 0 && $acceptedVotes > ($inspectorCount / 2);

            if ($majorityAccepted) {
                // Accept the full received quantity for this item.
                $item->update([
                    'status'            => 'accepted',
                    'quantity_accepted' => $item->quantity_received,
                    'quantity_rejected' => '0.00',
                ]);

                $anyAccepted = true;
                $allRejected = false;

                // Update PO item received_quantity
                $this->updatePoItemReceivedQty($item->purchaseOrderItem, $item->quantity_received);

                // Update inventory stock
                $this->updateInventoryForItem($grn, $item);
            } else {
                // Collect rejection notes from inspectors who voted against.
                $rejectionNotes = collect($votes)
                    ->filter(fn ($v) => ! (bool) $v['accepted'] && ! empty($v['notes']))
                    ->map(fn ($v) => $v['notes'])
                    ->implode('; ');

                $item->update([
                    'status'            => 'rejected',
                    'quantity_accepted' => '0.00',
                    'quantity_rejected' => $item->quantity_received,
                    'rejection_reason'  => $rejectionNotes ?: 'Rejected by inspection committee.',
                ]);

                // Notify Procurement_Officer and Supplier about rejection (Req 12.4)
                $this->notifyRejection($grn, $item);
            }
        }

        // Determine final GRN status
        if ($allRejected) {
            $grnStatus = 'rejected';
        } elseif ($anyAccepted) {
            // Check if all items accepted
            $grnStatus = $grn->items->every(fn ($i) => $i->fresh()->status === 'accepted')
                ? 'accepted'
                : 'partially_accepted';
        } else {
            $grnStatus = 'rejected';
        }

        $grn->update(['status' => $grnStatus]);

        // Recalculate PO overall status
        $this->recalculatePoStatus($grn->purchaseOrder->fresh(['items']));

        // Notify the Store_Manager who created the GRN
        $this->notifyStoreManagerOnFinalization($grn, $grnStatus);

        WriteAuditLogJob::dispatch(
            tenantId:   $grn->tenant_id,
            userId:     $grn->received_by,
            userRole:   'store_manager',
            actionType: 'goods_receipt.finalized',
            entityType: 'goods_receipt',
            entityId:   $grn->id,
            before:     ['status' => 'under_inspection'],
            after:      ['status' => $grnStatus],
            ipAddress:  '0.0.0.0',
            requestId:  null,
        )->onQueue('default');
    }

    // =========================================================================
    // Private: PO received quantity helpers
    // =========================================================================

    /**
     * Add the newly accepted quantity to the PO item's received_quantity.
     */
    private function updatePoItemReceivedQty(PurchaseOrderItem $poItem, string $acceptedQty): void
    {
        $current     = $this->normalise($poItem->received_quantity ?? '0');
        $newReceived = bcadd($current, $this->normalise($acceptedQty), self::SCALE);

        $poItem->update(['received_quantity' => $newReceived]);
    }

    /**
     * Recalculate the PO's overall status based on cumulative received quantities.
     *
     * - All items fully received   → fully_received
     * - At least one item received → partially_received
     * - Nothing changed            → status unchanged
     *
     * Requirements: 12.5
     */
    private function recalculatePoStatus(PurchaseOrder $po): void
    {
        $allFull   = true;
        $anyPartial = false;

        foreach ($po->items as $poItem) {
            $ordered  = $this->normalise($poItem->quantity ?? '0');
            $received = $this->normalise($poItem->received_quantity ?? '0');

            if (bccomp($received, '0.00', self::SCALE) > 0) {
                $anyPartial = true;
            }

            if (bccomp($received, $ordered, self::SCALE) < 0) {
                $allFull = false;
            }
        }

        if ($allFull && $anyPartial) {
            $po->update(['status' => 'fully_received']);
        } elseif ($anyPartial) {
            $po->update(['status' => 'partially_received']);
        }
    }

    // =========================================================================
    // Private: Inventory update
    // =========================================================================

    /**
     * Call InventoryService::updateStock() for an accepted GRN item.
     *
     * Uses the PO item's description and a derived item_code.
     * The item_code is derived from the PO item description if not otherwise
     * available (item_code is not a PO item attribute in the current schema).
     *
     * Requirements: 12.7
     */
    private function updateInventoryForItem(GoodsReceipt $grn, GoodsReceiptItem $item): void
    {
        $poItem = $item->purchaseOrderItem;

        // Derive an item code: slugified description trimmed to 100 chars
        $itemCode = strtoupper(
            preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($poItem->description))
        );
        $itemCode = substr($itemCode, 0, 100);

        try {
            // Resolve the actor from the GRN received_by user
            $actor = User::withoutGlobalScopes()->find($grn->received_by)
                ?? new User(['id' => $grn->received_by, 'tenant_id' => $grn->tenant_id]);

            $this->inventoryService->updateStock(
                warehouseId:   $grn->warehouse_id,
                itemCode:      $itemCode,
                itemName:      $poItem->description,
                quantityAdded: (string) $item->quantity_received,
                unitOfMeasure: $poItem->unit_of_measure ?? 'unit',
                category:      'General',
                tenantId:      $grn->tenant_id,
                actor:         $actor,
            );
        } catch (\Throwable $e) {
            Log::error('GoodsReceiptService: failed to update inventory', [
                'grn_id'  => $grn->id,
                'item_id' => $item->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Private: Validation helpers
    // =========================================================================

    /**
     * Validate required top-level fields for create().
     *
     * @throws InvalidArgumentException
     */
    private function validateCreateData(array $data): void
    {
        $required = ['purchase_order_id', 'warehouse_id', 'delivery_note_number', 'items'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $label = str_replace('_', ' ', $field);
                throw new InvalidArgumentException("The {$label} field is required.");
            }
        }

        if (! is_array($data['items']) || empty($data['items'])) {
            throw new InvalidArgumentException('At least one line item is required.');
        }

        foreach ($data['items'] as $index => $item) {
            if (empty($item['po_item_id'])) {
                throw new InvalidArgumentException("Item #{$index}: po_item_id is required.");
            }
            if (! isset($item['received_quantity']) || $item['received_quantity'] === '') {
                throw new InvalidArgumentException("Item #{$index}: received_quantity is required.");
            }
            if (bccomp($this->normalise($item['received_quantity']), '0.00', self::SCALE) <= 0) {
                throw new InvalidArgumentException("Item #{$index}: received_quantity must be greater than zero.");
            }
        }
    }

    /**
     * Validate that the received quantities do not exceed outstanding PO quantities.
     *
     * Outstanding = PO item quantity − already received quantity (across all prior GRNs).
     *
     * @param  PurchaseOrder  $po
     * @param  array<int, array{po_item_id: string, received_quantity: mixed}>  $items
     *
     * @throws InvalidArgumentException
     */
    private function validateReceivedQuantities(PurchaseOrder $po, array $items): void
    {
        foreach ($items as $index => $item) {
            $poItem = $po->items->firstWhere('id', $item['po_item_id']);

            if (! $poItem) {
                throw new InvalidArgumentException(
                    "Item #{$index}: po_item_id '{$item['po_item_id']}' does not belong to PO {$po->po_number}."
                );
            }

            $ordered         = $this->normalise($poItem->quantity);
            $alreadyReceived = $this->normalise($poItem->received_quantity ?? '0');
            $outstanding     = bcsub($ordered, $alreadyReceived, self::SCALE);
            $receiving       = $this->normalise($item['received_quantity']);

            if (bccomp($receiving, $outstanding, self::SCALE) > 0) {
                throw new InvalidArgumentException(
                    "Item #{$index} ('{$poItem->description}'): received_quantity ({$receiving}) exceeds "
                    . "outstanding quantity ({$outstanding}). Ordered: {$ordered}, Already received: {$alreadyReceived}."
                );
            }
        }
    }

    // =========================================================================
    // Private: Notification helpers
    // =========================================================================

    /**
     * Notify the Procurement_Officer and the Supplier when an item is rejected.
     *
     * Requirements: 12.4
     */
    private function notifyRejection(GoodsReceipt $grn, GoodsReceiptItem $item): void
    {
        $po = $grn->purchaseOrder ?? PurchaseOrder::withoutGlobalScopes()->find($grn->purchase_order_id);

        if (! $po) {
            return;
        }

        // Notify Procurement_Officer
        if ($po->created_by) {
            $this->createNotification(
                tenantId:  $grn->tenant_id,
                userId:    $po->created_by,
                eventType: 'grn_item_rejected',
                title:     "GRN Item Rejected: {$grn->grn_number}",
                message:   "Item '{$item->description}' on GRN {$grn->grn_number} was rejected by the "
                           . "inspection committee. Reason: {$item->rejection_reason}",
                data:      [
                    'grn_id'        => $grn->id,
                    'grn_number'    => $grn->grn_number,
                    'item_id'       => $item->id,
                    'description'   => $item->description,
                    'rejection_reason' => $item->rejection_reason,
                ],
            );
        }

        // Notify Supplier via their linked user account
        $supplier = \App\Models\Supplier::withoutGlobalScopes()
            ->where('id', $po->supplier_id)
            ->whereNotNull('user_id')
            ->first();

        if ($supplier) {
            $this->createNotification(
                tenantId:  $grn->tenant_id,
                userId:    $supplier->user_id,
                eventType: 'grn_item_rejected',
                title:     "Delivery Item Rejected: GRN {$grn->grn_number}",
                message:   "An item in your delivery against PO {$po->po_number} has been rejected. "
                           . "Item: '{$item->description}'. Reason: {$item->rejection_reason}",
                data:      [
                    'grn_id'          => $grn->id,
                    'grn_number'      => $grn->grn_number,
                    'po_number'       => $po->po_number,
                    'item_description'=> $item->description,
                    'rejection_reason'=> $item->rejection_reason,
                ],
            );
        }
    }

    /**
     * Notify the Store_Manager (GRN received_by) when inspection is finalized.
     *
     * Requirements: 12.6
     */
    private function notifyStoreManagerOnFinalization(GoodsReceipt $grn, string $finalStatus): void
    {
        if (! $grn->received_by) {
            return;
        }

        $statusLabel = match ($finalStatus) {
            'accepted'          => 'fully accepted',
            'partially_accepted'=> 'partially accepted',
            'rejected'          => 'rejected',
            default             => $finalStatus,
        };

        $this->createNotification(
            tenantId:  $grn->tenant_id,
            userId:    $grn->received_by,
            eventType: 'grn_inspection_finalized',
            title:     "GRN Inspection Finalized: {$grn->grn_number}",
            message:   "The inspection for GRN {$grn->grn_number} has been finalized. "
                       . "Result: {$statusLabel}. "
                       . "A Delivery Note is now available for download.",
            data:      [
                'grn_id'     => $grn->id,
                'grn_number' => $grn->grn_number,
                'status'     => $finalStatus,
            ],
        );
    }

    /**
     * Helper to create a Notification record without throwing on failure.
     */
    private function createNotification(
        string $tenantId,
        string $userId,
        string $eventType,
        string $title,
        string $message,
        array  $data = [],
    ): void {
        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $tenantId,
                'user_id'    => $userId,
                'event_type' => $eventType,
                'title'      => $title,
                'message'    => $message,
                'data'       => $data,
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('GoodsReceiptService: failed to create notification', [
                'event_type' => $eventType,
                'user_id'    => $userId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Private: BCMath helper
    // =========================================================================

    /**
     * Normalise a numeric value to a BCMath-safe string with SCALE decimal places.
     */
    private function normalise(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', self::SCALE);
    }
}
