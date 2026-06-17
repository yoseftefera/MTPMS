<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Budget;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PurchaseOrderService — full PO lifecycle within a tenant.
 *
 * PO number format: PO-{TENANT_CODE}-{YEAR}-{SEQUENCE}
 * Example: PO-ACME-2024-00001
 *
 * Status flow:
 *  draft → issued → accepted   (normal acceptance path)
 *  draft → issued → rejected   (supplier rejects)
 *  draft/issued/accepted → cancelled
 *
 * Budget lifecycle:
 *  - generate()  : encumber total_amount from department budget (draft status)
 *  - reject()    : release encumbrance
 *  - cancel()    : release encumbrance
 *
 * Amendments:
 *  - pre-acceptance (draft/issued) : free amendment, no supplier acknowledgment needed
 *  - post-acceptance               : sets pending_supplier_acknowledgment = true
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.8, 10.9, 10.10
 */
class PurchaseOrderService
{
    /**
     * BCMath decimal scale — matches DECIMAL(15,2) database columns.
     */
    private const SCALE = 2;

    /**
     * Statuses from which a PO may be cancelled.
     */
    private const CANCELLABLE_STATUSES = ['draft', 'issued', 'accepted'];

    /**
     * Statuses that are considered "pre-acceptance" for amendment rules.
     */
    private const PRE_ACCEPTANCE_STATUSES = ['draft', 'issued'];

    /**
     * Terminal statuses — amendments and cancellations are blocked.
     */
    private const NON_AMENDABLE_STATUSES = ['rejected', 'cancelled', 'fully_received', 'overdue'];

    public function __construct(
        private readonly BudgetService $budgetService,
    ) {}

    // =========================================================================
    // PO Number Generation
    // =========================================================================

    /**
     * Generate the next sequential PO number for the given tenant and year.
     *
     * Format: PO-{TENANT_CODE}-{YEAR}-{SEQUENCE}  (SEQUENCE zero-padded to 5 digits)
     * Example: PO-ACME-2024-00001
     *
     * A pessimistic lock (SELECT … FOR UPDATE) on the purchase_orders table is
     * used inside a database transaction to prevent duplicate sequences under
     * concurrent submissions.
     *
     * Requirements: 10.1
     */
    public function generatePONumber(string $tenantCode, int $year = 0): string
    {
        if ($year === 0) {
            $year = now()->year;
        }

        $tenantCode = strtoupper($tenantCode);

        $sequence = DB::transaction(function () use ($tenantCode, $year) {
            // Resolve the tenant by code to scope the count correctly
            $tenant = Tenant::withoutGlobalScopes()
                ->where('tenant_code', $tenantCode)
                ->first();

            if (! $tenant) {
                // Fallback: count without tenant scope (should not happen in normal usage)
                return 1;
            }

            // Lock all existing PO rows for this tenant+year so concurrent
            // transactions must wait, guaranteeing a unique sequence number.
            $count = DB::table('purchase_orders')
                ->where('tenant_id', $tenant->id)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();

            return $count + 1;
        });

        return sprintf('PO-%s-%d-%05d', $tenantCode, $year, $sequence);
    }

    // =========================================================================
    // Generate (Create)
    // =========================================================================

    /**
     * Create a new Purchase Order in `draft` status and encumber the budget.
     *
     * Required fields:
     *   supplier_id, department_id, delivery_address, required_delivery_date, items
     *
     * Optional:
     *   purchase_request_id, bid_id, currency
     *
     * Calculates total_amount = Σ(quantity × unit_price) using BCMath.
     * Encumbers the total amount from the department's current-year budget.
     *
     * Requirements: 10.1, 10.2
     *
     * @param  array{
     *     supplier_id: string,
     *     department_id: string,
     *     delivery_address: string,
     *     required_delivery_date: string,
     *     items: array<int, array{
     *         description: string,
     *         quantity: string|int|float,
     *         unit_of_measure: string,
     *         unit_price: string|int|float,
     *     }>,
     *     purchase_request_id?: string|null,
     *     bid_id?: string|null,
     *     currency?: string,
     * }  $data
     *
     * @throws InvalidArgumentException  when required fields are missing or items is empty
     */
    public function generate(array $data, User $actor): PurchaseOrder
    {
        $this->validateGenerateData($data);

        return DB::transaction(function () use ($data, $actor) {
            $tenant    = $actor->tenant ?? app('tenant');
            $tenantId  = $actor->tenant_id ?? $tenant->id;
            $tenantCode = strtoupper($tenant->tenant_code);

            $poNumber    = $this->generatePONumber($tenantCode);
            $totalAmount = $this->calculateTotal($data['items']);

            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::create([
                'po_number'              => $poNumber,
                'tenant_id'              => $tenantId,
                'purchase_request_id'    => $data['purchase_request_id'] ?? null,
                'bid_id'                 => $data['bid_id'] ?? null,
                'supplier_id'            => $data['supplier_id'],
                'department_id'          => $data['department_id'],
                'status'                 => 'draft',
                'total_amount'           => $totalAmount,
                'currency'               => $data['currency'] ?? 'USD',
                'delivery_address'       => $data['delivery_address'],
                'required_delivery_date' => $data['required_delivery_date'],
                'created_by'             => $actor->id,
            ]);

            $this->createItems($po, $data['items']);

            // Encumber budget for this PO
            $budget = $this->budgetService->getActiveBudgetForDepartment(
                departmentId: $data['department_id'],
                fiscalYear:   now()->year,
            );

            if ($budget) {
                $this->budgetService->encumberAmount(
                    budget:        $budget,
                    amount:        $totalAmount,
                    referenceType: 'purchase_order',
                    referenceId:   $po->id,
                    actor:         $actor,
                );
            }

            WriteAuditLogJob::dispatch(
                tenantId:   $tenantId,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'purchase_order.created',
                entityType: 'purchase_order',
                entityId:   $po->id,
                before:     null,
                after:      [
                    'po_number'    => $po->po_number,
                    'status'       => 'draft',
                    'total_amount' => $totalAmount,
                    'supplier_id'  => $data['supplier_id'],
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            return $po->load(['items', 'supplier', 'department', 'createdBy']);
        });
    }

    // =========================================================================
    // Issue (draft → issued)
    // =========================================================================

    /**
     * Issue a Purchase Order to the supplier (draft → issued).
     *
     * Sends an in-app notification to the supplier's user account.
     * Sets issued_at timestamp.
     *
     * Requirements: 10.3
     *
     * @throws InvalidArgumentException  when PO is not in `draft` status
     */
    public function issue(PurchaseOrder $po, User $actor): void
    {
        if ($po->status !== 'draft') {
            throw new InvalidArgumentException(
                "Purchase order {$po->po_number} cannot be issued: "
                . "only draft POs can be issued (current status: {$po->status})."
            );
        }

        DB::transaction(function () use ($po, $actor) {
            $before = ['status' => $po->status, 'issued_at' => null];

            $po->update([
                'status'    => 'issued',
                'issued_at' => now(),
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $po->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'purchase_order.issued',
                entityType: 'purchase_order',
                entityId:   $po->id,
                before:     $before,
                after:      ['status' => 'issued', 'issued_at' => now()->toIso8601String()],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            // Notify the supplier
            $this->notifySupplierOnIssue($po);
        });
    }

    // =========================================================================
    // Accept (issued → accepted)
    // =========================================================================

    /**
     * Supplier accepts a Purchase Order (issued → accepted).
     *
     * Sets accepted_at timestamp. Notifies the Procurement_Officer.
     *
     * Requirements: 10.4
     *
     * @throws InvalidArgumentException  when PO is not in `issued` status
     */
    public function accept(PurchaseOrder $po, User $actor): void
    {
        if ($po->status !== 'issued') {
            throw new InvalidArgumentException(
                "Purchase order {$po->po_number} cannot be accepted: "
                . "only issued POs can be accepted (current status: {$po->status})."
            );
        }

        DB::transaction(function () use ($po, $actor) {
            $before = ['status' => $po->status, 'accepted_at' => null];

            $po->update([
                'status'      => 'accepted',
                'accepted_at' => now(),
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $po->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'supplier',
                actionType: 'purchase_order.accepted',
                entityType: 'purchase_order',
                entityId:   $po->id,
                before:     $before,
                after:      ['status' => 'accepted', 'accepted_at' => now()->toIso8601String()],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            // Notify the Procurement_Officer who created the PO
            $this->notifyProcurementOfficerOnAccept($po);
        });
    }

    // =========================================================================
    // Reject (issued → rejected)
    // =========================================================================

    /**
     * Supplier rejects a Purchase Order (issued → rejected).
     *
     * Releases the budget encumbrance. Notifies the Procurement_Officer.
     * A rejection reason is required.
     *
     * Requirements: 10.5
     *
     * @throws InvalidArgumentException  when PO is not in `issued` status
     * @throws InvalidArgumentException  when reason is empty
     */
    public function reject(PurchaseOrder $po, string $reason, User $actor): void
    {
        if ($po->status !== 'issued') {
            throw new InvalidArgumentException(
                "Purchase order {$po->po_number} cannot be rejected: "
                . "only issued POs can be rejected (current status: {$po->status})."
            );
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('A rejection reason is required and cannot be empty.');
        }

        DB::transaction(function () use ($po, $reason, $actor) {
            $before = ['status' => $po->status];

            $po->update([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
            ]);

            // Release budget encumbrance
            $this->releaseEncumbranceForPO($po, $actor);

            WriteAuditLogJob::dispatch(
                tenantId:   $po->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'supplier',
                actionType: 'purchase_order.rejected',
                entityType: 'purchase_order',
                entityId:   $po->id,
                before:     $before,
                after:      ['status' => 'rejected', 'rejection_reason' => $reason],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            // Notify the Procurement_Officer
            $this->notifyProcurementOfficerOnReject($po, $reason);
        });
    }

    // =========================================================================
    // Amend
    // =========================================================================

    /**
     * Amend a Purchase Order.
     *
     * Pre-acceptance (draft/issued): free amendment — changes applied immediately.
     * Post-acceptance (accepted):    sets pending_supplier_acknowledgment = true.
     * Rejected/cancelled/completed:  not allowed.
     *
     * Amendable fields: delivery_address, required_delivery_date, items, notes
     *
     * Requirements: 10.9
     *
     * @param  array{
     *     delivery_address?: string,
     *     required_delivery_date?: string,
     *     notes?: string,
     *     items?: array<int, array{
     *         description: string,
     *         quantity: string|int|float,
     *         unit_of_measure: string,
     *         unit_price: string|int|float,
     *     }>,
     * }  $changes
     *
     * @throws InvalidArgumentException  when PO is in a non-amendable status
     */
    public function amend(PurchaseOrder $po, array $changes, User $actor): void
    {
        $nonAmendableStatuses = ['rejected', 'cancelled', 'fully_received', 'overdue'];

        if (in_array($po->status, $nonAmendableStatuses, true)) {
            throw new InvalidArgumentException(
                "Purchase order {$po->po_number} cannot be amended: "
                . "amendments are not allowed in '{$po->status}' status."
            );
        }

        DB::transaction(function () use ($po, $changes, $actor) {
            $before    = $po->only(['status', 'delivery_address', 'required_delivery_date', 'total_amount']);
            $updateData = [];

            if (isset($changes['delivery_address'])) {
                $updateData['delivery_address'] = $changes['delivery_address'];
            }

            if (isset($changes['required_delivery_date'])) {
                $updateData['required_delivery_date'] = $changes['required_delivery_date'];
            }

            if (isset($changes['notes'])) {
                $updateData['notes'] = $changes['notes'];
            }

            // Handle item replacement
            if (isset($changes['items']) && is_array($changes['items'])) {
                if (empty($changes['items'])) {
                    throw new InvalidArgumentException('At least one line item is required.');
                }

                $this->validateItems($changes['items']);

                // Calculate old and new totals for budget adjustment
                $oldTotal = $this->normalise($po->total_amount);
                $newTotal = $this->calculateTotal($changes['items']);

                // Replace items
                PurchaseOrderItem::where('purchase_order_id', $po->id)->delete();
                $this->createItems($po, $changes['items']);

                $updateData['total_amount'] = $newTotal;

                // Adjust budget encumbrance if total changed
                $this->adjustEncumbrance($po, $oldTotal, $newTotal, $actor);
            }

            // Post-acceptance amendments require supplier acknowledgment
            if ($po->status === 'accepted') {
                $updateData['pending_supplier_acknowledgment'] = true;
            }

            if (! empty($updateData)) {
                $po->update($updateData);
            }

            $afterData = array_merge(
                $po->fresh()->only(['status', 'delivery_address', 'required_delivery_date', 'total_amount']),
                ['pending_supplier_acknowledgment' => $po->fresh()->pending_supplier_acknowledgment ?? false],
            );

            WriteAuditLogJob::dispatch(
                tenantId:   $po->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'purchase_order.amended',
                entityType: 'purchase_order',
                entityId:   $po->id,
                before:     $before,
                after:      $afterData,
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // Cancel
    // =========================================================================

    /**
     * Cancel a Purchase Order.
     *
     * Allowed from: draft, issued, accepted
     * Releases the budget encumbrance.
     * A cancellation reason is required.
     *
     * Requirements: 10.10 (via 13.5)
     *
     * @throws InvalidArgumentException  when PO cannot be cancelled from its current status
     * @throws InvalidArgumentException  when reason is empty
     */
    public function cancel(PurchaseOrder $po, string $reason, User $actor): void
    {
        if (! in_array($po->status, self::CANCELLABLE_STATUSES, true)) {
            $allowed = implode(', ', self::CANCELLABLE_STATUSES);
            throw new InvalidArgumentException(
                "Purchase order {$po->po_number} cannot be cancelled from status '{$po->status}'. "
                . "Cancellation is allowed from: {$allowed}."
            );
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('A cancellation reason is required and cannot be empty.');
        }

        DB::transaction(function () use ($po, $reason, $actor) {
            $before = ['status' => $po->status];

            $po->update([
                'status'              => 'cancelled',
                'cancellation_reason' => $reason,
            ]);

            // Release budget encumbrance
            $this->releaseEncumbranceForPO($po, $actor);

            WriteAuditLogJob::dispatch(
                tenantId:   $po->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'purchase_order.cancelled',
                entityType: 'purchase_order',
                entityId:   $po->id,
                before:     $before,
                after:      ['status' => 'cancelled', 'cancellation_reason' => $reason],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // Search / List
    // =========================================================================

    /**
     * Return a paginated list of purchase orders within the active tenant scope.
     *
     * Supported filters:
     *   status       — filter by PO status
     *   supplier_id  — filter by supplier UUID
     *   date_from    — filter created_at >= date (Y-m-d)
     *   date_to      — filter created_at <= date (Y-m-d)
     *   po_number    — partial match on PO number
     *
     * Requirements: 10.1
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['supplier', 'department', 'createdBy', 'items']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['po_number'])) {
            $query->where('po_number', 'like', '%' . $filters['po_number'] . '%');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // =========================================================================
    // Private: Budget helpers
    // =========================================================================

    /**
     * Release the budget encumbrance for a PO (used on reject/cancel).
     */
    private function releaseEncumbranceForPO(PurchaseOrder $po, User $actor): void
    {
        $budget = $this->budgetService->getActiveBudgetForDepartment(
            departmentId: $po->department_id,
            fiscalYear:   now()->year,
        );

        if ($budget) {
            $this->budgetService->releaseEncumbrance(
                budget:        $budget,
                amount:        $this->normalise($po->total_amount),
                referenceType: 'purchase_order',
                referenceId:   $po->id,
                actor:         $actor,
            );
        }
    }

    /**
     * Adjust the budget encumbrance when the PO total changes due to an amendment.
     *
     * If new total > old total  → encumber the difference.
     * If new total < old total  → release the difference.
     * If equal                  → no-op.
     */
    private function adjustEncumbrance(PurchaseOrder $po, string $oldTotal, string $newTotal, User $actor): void
    {
        $diff = bcsub($newTotal, $oldTotal, self::SCALE);

        if (bccomp($diff, '0.00', self::SCALE) === 0) {
            return;
        }

        $budget = $this->budgetService->getActiveBudgetForDepartment(
            departmentId: $po->department_id,
            fiscalYear:   now()->year,
        );

        if (! $budget) {
            return;
        }

        if (bccomp($diff, '0.00', self::SCALE) > 0) {
            // Total increased — encumber more
            $this->budgetService->encumberAmount(
                budget:        $budget,
                amount:        $diff,
                referenceType: 'purchase_order',
                referenceId:   $po->id,
                actor:         $actor,
            );
        } else {
            // Total decreased — release the surplus
            $this->budgetService->releaseEncumbrance(
                budget:        $budget,
                amount:        bcmul($diff, '-1', self::SCALE), // make positive
                referenceType: 'purchase_order',
                referenceId:   $po->id,
                actor:         $actor,
            );
        }
    }

    // =========================================================================
    // Private: Notification helpers
    // =========================================================================

    /**
     * Notify the supplier when a PO is issued to them.
     *
     * Requirements: 10.3
     */
    private function notifySupplierOnIssue(PurchaseOrder $po): void
    {
        $supplier = Supplier::withoutGlobalScopes()
            ->where('id', $po->supplier_id)
            ->whereNotNull('user_id')
            ->first();

        if (! $supplier) {
            return;
        }

        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $po->tenant_id,
                'user_id'    => $supplier->user_id,
                'event_type' => 'purchase_order_issued',
                'title'      => "New Purchase Order: {$po->po_number}",
                'message'    => "A new purchase order ({$po->po_number}) has been issued to you. "
                              . "Please review and confirm acceptance.",
                'data'       => [
                    'purchase_order_id' => $po->id,
                    'po_number'         => $po->po_number,
                    'total_amount'      => $po->total_amount,
                    'currency'          => $po->currency,
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('PurchaseOrderService: failed to notify supplier on issue', [
                'po_id'      => $po->id,
                'supplier_id' => $po->supplier_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the Procurement_Officer who created the PO when it is accepted.
     *
     * Requirements: 10.4
     */
    private function notifyProcurementOfficerOnAccept(PurchaseOrder $po): void
    {
        if (! $po->created_by) {
            return;
        }

        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $po->tenant_id,
                'user_id'    => $po->created_by,
                'event_type' => 'purchase_order_accepted',
                'title'      => "PO Accepted: {$po->po_number}",
                'message'    => "Purchase order {$po->po_number} has been accepted by the supplier.",
                'data'       => [
                    'purchase_order_id' => $po->id,
                    'po_number'         => $po->po_number,
                    'accepted_at'       => now()->toIso8601String(),
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('PurchaseOrderService: failed to notify officer on accept', [
                'po_id' => $po->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the Procurement_Officer who created the PO when it is rejected.
     *
     * Requirements: 10.5
     */
    private function notifyProcurementOfficerOnReject(PurchaseOrder $po, string $reason): void
    {
        if (! $po->created_by) {
            return;
        }

        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $po->tenant_id,
                'user_id'    => $po->created_by,
                'event_type' => 'purchase_order_rejected',
                'title'      => "PO Rejected: {$po->po_number}",
                'message'    => "Purchase order {$po->po_number} has been rejected by the supplier. "
                              . "Reason: {$reason}",
                'data'       => [
                    'purchase_order_id' => $po->id,
                    'po_number'         => $po->po_number,
                    'rejection_reason'  => $reason,
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('PurchaseOrderService: failed to notify officer on reject', [
                'po_id' => $po->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Private: Data helpers
    // =========================================================================

    /**
     * Validate required fields for the generate() method.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private function validateGenerateData(array $data): void
    {
        $required = ['supplier_id', 'department_id', 'delivery_address', 'required_delivery_date', 'items'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $label = str_replace('_', ' ', $field);
                throw new InvalidArgumentException("The {$label} field is required.");
            }
        }

        if (! is_array($data['items']) || empty($data['items'])) {
            throw new InvalidArgumentException('Purchase order must contain at least one line item.');
        }

        $this->validateItems($data['items']);
    }

    /**
     * Validate each item in the items array.
     *
     * Required per item: description, quantity, unit_of_measure, unit_price
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws InvalidArgumentException
     */
    private function validateItems(array $items): void
    {
        foreach ($items as $index => $item) {
            $position = $index + 1;

            if (empty($item['description'])) {
                throw new InvalidArgumentException("Item {$position}: the 'description' field is required.");
            }

            if (! isset($item['quantity']) || $item['quantity'] === '') {
                throw new InvalidArgumentException("Item {$position}: the 'quantity' field is required.");
            }

            if (empty($item['unit_of_measure'])) {
                throw new InvalidArgumentException("Item {$position}: the 'unit_of_measure' field is required.");
            }

            if (! isset($item['unit_price']) || $item['unit_price'] === '') {
                throw new InvalidArgumentException("Item {$position}: the 'unit_price' field is required.");
            }
        }
    }

    /**
     * Bulk-create PurchaseOrderItem records for a given PO.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createItems(PurchaseOrder $po, array $items): void
    {
        $records = array_map(function (array $item) use ($po) {
            $quantity   = (string) $item['quantity'];
            $unitPrice  = (string) $item['unit_price'];
            $totalPrice = bcmul($quantity, $unitPrice, self::SCALE);

            return [
                'purchase_order_id' => $po->id,
                'tenant_id'         => $po->tenant_id,
                'description'       => $item['description'],
                'quantity'          => $quantity,
                'received_quantity' => '0.00',
                'unit_of_measure'   => $item['unit_of_measure'],
                'unit_price'        => $unitPrice,
                'total_price'       => $totalPrice,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }, $items);

        foreach (array_chunk($records, 500) as $chunk) {
            PurchaseOrderItem::insert($chunk);
        }
    }

    /**
     * Calculate the total amount for a set of line items using BCMath.
     *
     * total_amount = Σ(quantity × unit_price)  [scale = 2]
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function calculateTotal(array $items): string
    {
        $total = '0.00';

        foreach ($items as $item) {
            $lineTotal = bcmul(
                (string) $item['quantity'],
                (string) $item['unit_price'],
                self::SCALE,
            );

            $total = bcadd($total, $lineTotal, self::SCALE);
        }

        return $total;
    }

    /**
     * Normalise a monetary value to a string with exactly SCALE decimal places.
     *
     * @param  string|int|float  $value
     */
    private function normalise(string|int|float $value): string
    {
        if (is_float($value)) {
            $value = number_format($value, self::SCALE + 4, '.', '');
        }

        return bcadd(trim((string) $value), '0', self::SCALE);
    }
}
