<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Contract;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * InvoiceService — full invoice lifecycle within a tenant.
 *
 * Invoice number format: INV-{TENANT_CODE}-{YEAR}-{SEQUENCE}
 * Example: INV-ACME-2024-00001
 *
 * Status flow:
 *  pending_approval → approved   (Finance_Officer approves)
 *  pending_approval → rejected   (Finance_Officer rejects with reason)
 *  approved → partially_paid     (partial payment recorded by PaymentService)
 *  approved/partially_paid → paid (full payment settled by PaymentService)
 *
 * Validation:
 *  - Invoiced total must not exceed the linked PO total_amount or Contract total_value.
 *  - If linked to a PO, at least one GoodsReceipt must exist with status in
 *    [accepted, partially_accepted] for that PO.
 *
 * Requirements: 14.1, 14.2, 14.3, 14.4
 */
class InvoiceService
{
    /**
     * BCMath decimal scale — matches DECIMAL(15,2) database columns.
     */
    private const SCALE = 2;

    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    // =========================================================================
    // Invoice Number Generation
    // =========================================================================

    /**
     * Generate the next sequential invoice number for the given tenant and year.
     *
     * Format: INV-{TENANT_CODE}-{YEAR}-{SEQUENCE}  (SEQUENCE zero-padded to 5 digits)
     * Example: INV-ACME-2024-00001
     *
     * A pessimistic lock (SELECT … FOR UPDATE) on the invoices table is used
     * inside a database transaction to prevent duplicate sequences under
     * concurrent submissions.
     *
     * Requirements: 14.1
     */
    public function generateInvoiceNumber(string $tenantCode, int $year = 0): string
    {
        if ($year === 0) {
            $year = now()->year;
        }

        $tenantCode = strtoupper($tenantCode);

        $sequence = DB::transaction(function () use ($tenantCode, $year) {
            $tenant = Tenant::withoutGlobalScopes()
                ->where('tenant_code', $tenantCode)
                ->first();

            if (! $tenant) {
                return 1;
            }

            $count = DB::table('invoices')
                ->where('tenant_id', $tenant->id)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();

            return $count + 1;
        });

        return sprintf('INV-%s-%d-%05d', $tenantCode, $year, $sequence);
    }

    // =========================================================================
    // Submit
    // =========================================================================

    /**
     * Submit a new Invoice for Finance_Officer approval.
     *
     * Required fields:
     *   supplier_id, total_amount, invoice_date, due_date
     *   + at least one of: purchase_order_id, contract_id
     *
     * Validation performed:
     *   1. At least one of purchase_order_id / contract_id must be provided.
     *   2. Invoiced total ≤ PO total_amount or Contract total_value (bcmath comparison).
     *   3. If purchase_order_id provided: a GoodsReceipt with status in
     *      [accepted, partially_accepted] must exist for that PO.
     *
     * Requirements: 14.1, 14.2, 14.3
     *
     * @param  array{
     *     supplier_id: string,
     *     purchase_order_id?: string|null,
     *     contract_id?: string|null,
     *     total_amount: string|float,
     *     currency?: string,
     *     invoice_date: string,
     *     due_date: string,
     *     paid_amount?: string|null,
     * }  $data
     *
     * @throws InvalidArgumentException  when validation fails
     */
    public function submit(array $data, User $actor): Invoice
    {
        // ── Required field guards ─────────────────────────────────────────────
        foreach (['supplier_id', 'total_amount', 'invoice_date', 'due_date'] as $field) {
            if (empty($data[$field])) {
                $label = str_replace('_', ' ', $field);
                throw new InvalidArgumentException("The {$label} field is required.");
            }
        }

        if (empty($data['purchase_order_id']) && empty($data['contract_id'])) {
            throw new InvalidArgumentException(
                'An invoice must reference either a Purchase Order or a Contract.'
            );
        }

        return DB::transaction(function () use ($data, $actor) {
            $invoicedAmount = $this->normalise($data['total_amount']);

            // ── PO validation ─────────────────────────────────────────────────
            if (! empty($data['purchase_order_id'])) {
                $po = PurchaseOrder::withoutGlobalScopes()
                    ->where('id', $data['purchase_order_id'])
                    ->first();

                if (! $po) {
                    throw new InvalidArgumentException('The referenced Purchase Order does not exist.');
                }

                $poTotal = $this->normalise($po->total_amount);
                if (bccomp($invoicedAmount, $poTotal, self::SCALE) > 0) {
                    throw new InvalidArgumentException(
                        "Invoice total ({$invoicedAmount}) exceeds Purchase Order total ({$poTotal}). "
                        . 'Discrepancy: ' . bcsub($invoicedAmount, $poTotal, self::SCALE) . '.'
                    );
                }

                // Verify that goods have been received and accepted for this PO
                $hasAcceptedReceipt = GoodsReceipt::withoutGlobalScopes()
                    ->where('purchase_order_id', $data['purchase_order_id'])
                    ->whereIn('status', ['accepted', 'partially_accepted'])
                    ->exists();

                if (! $hasAcceptedReceipt) {
                    throw new InvalidArgumentException(
                        'Cannot submit an invoice for this Purchase Order: '
                        . 'no accepted Goods Receipt exists for the referenced PO.'
                    );
                }
            }

            // ── Contract validation ───────────────────────────────────────────
            if (! empty($data['contract_id'])) {
                $contract = Contract::withoutGlobalScopes()
                    ->where('id', $data['contract_id'])
                    ->first();

                if (! $contract) {
                    throw new InvalidArgumentException('The referenced Contract does not exist.');
                }

                $contractTotal = $this->normalise($contract->total_value);
                if (bccomp($invoicedAmount, $contractTotal, self::SCALE) > 0) {
                    throw new InvalidArgumentException(
                        "Invoice total ({$invoicedAmount}) exceeds Contract total value ({$contractTotal}). "
                        . 'Discrepancy: ' . bcsub($invoicedAmount, $contractTotal, self::SCALE) . '.'
                    );
                }
            }

            // ── Generate invoice number ───────────────────────────────────────
            $tenant     = $actor->tenant ?? app('tenant');
            $tenantId   = $actor->tenant_id ?? $tenant->id;
            $tenantCode = strtoupper($tenant->tenant_code);

            $invoiceNumber = $this->generateInvoiceNumber($tenantCode);

            // ── Persist ───────────────────────────────────────────────────────
            /** @var Invoice $invoice */
            $invoice = Invoice::create([
                'invoice_number'     => $invoiceNumber,
                'tenant_id'          => $tenantId,
                'supplier_id'        => $data['supplier_id'],
                'purchase_order_id'  => $data['purchase_order_id'] ?? null,
                'contract_id'        => $data['contract_id'] ?? null,
                'total_amount'       => $invoicedAmount,
                'paid_amount'        => '0.00',
                'currency'           => $data['currency'] ?? 'USD',
                'invoice_date'       => $data['invoice_date'],
                'due_date'           => $data['due_date'],
                'status'             => 'pending_approval',
                'submitted_at'       => now(),
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $tenantId,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'supplier',
                actionType: 'invoice.submitted',
                entityType: 'invoice',
                entityId:   $invoice->id,
                before:     null,
                after:      [
                    'invoice_number' => $invoice->invoice_number,
                    'status'         => 'pending_approval',
                    'total_amount'   => $invoicedAmount,
                    'supplier_id'    => $data['supplier_id'],
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            return $invoice->load(['supplier', 'purchaseOrder', 'contract']);
        });
    }

    // =========================================================================
    // Approve
    // =========================================================================

    /**
     * Approve an invoice (pending_approval → approved).
     *
     * Creates a corresponding Payment record via PaymentService.
     * Notifies the supplier user.
     *
     * Requirements: 14.4, 14.5
     *
     * @throws InvalidArgumentException  when invoice is not in pending_approval status
     */
    public function approve(Invoice $invoice, User $actor): void
    {
        if ($invoice->status !== 'pending_approval') {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} cannot be approved: "
                . "only invoices in 'pending_approval' status can be approved (current: {$invoice->status})."
            );
        }

        DB::transaction(function () use ($invoice, $actor) {
            $before = ['status' => $invoice->status];

            $invoice->update(['status' => 'approved']);

            // Create the payment record
            $this->paymentService->createFromInvoice($invoice, $actor);

            WriteAuditLogJob::dispatch(
                tenantId:   $invoice->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'finance_officer',
                actionType: 'invoice.approved',
                entityType: 'invoice',
                entityId:   $invoice->id,
                before:     $before,
                after:      ['status' => 'approved'],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            $this->notifySupplier($invoice, 'invoice_approved', 'Invoice Approved', [
                'title'   => "Invoice {$invoice->invoice_number} Approved",
                'message' => "Your invoice {$invoice->invoice_number} has been approved and payment is being processed.",
            ]);
        });
    }

    // =========================================================================
    // Reject
    // =========================================================================

    /**
     * Reject an invoice (pending_approval → rejected).
     *
     * A non-empty rejection reason is required.
     * Notifies the supplier user.
     *
     * Requirements: 14.4
     *
     * @throws InvalidArgumentException  when invoice is not in pending_approval status
     * @throws InvalidArgumentException  when reason is empty
     */
    public function reject(Invoice $invoice, string $reason, User $actor): void
    {
        if ($invoice->status !== 'pending_approval') {
            throw new InvalidArgumentException(
                "Invoice {$invoice->invoice_number} cannot be rejected: "
                . "only invoices in 'pending_approval' status can be rejected (current: {$invoice->status})."
            );
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('A rejection reason is required and cannot be empty.');
        }

        DB::transaction(function () use ($invoice, $reason, $actor) {
            $before = ['status' => $invoice->status];

            $invoice->update([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $invoice->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'finance_officer',
                actionType: 'invoice.rejected',
                entityType: 'invoice',
                entityId:   $invoice->id,
                before:     $before,
                after:      ['status' => 'rejected', 'rejection_reason' => $reason],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            $this->notifySupplier($invoice, 'invoice_rejected', 'Invoice Rejected', [
                'title'   => "Invoice {$invoice->invoice_number} Rejected",
                'message' => "Your invoice {$invoice->invoice_number} has been rejected. Reason: {$reason}",
            ]);
        });
    }

    // =========================================================================
    // Search / List
    // =========================================================================

    /**
     * Return a paginated list of invoices within the active tenant scope.
     *
     * Supported filters:
     *   status      — filter by invoice status
     *   supplier_id — filter by supplier UUID
     *   date_from   — filter invoice_date >= date (Y-m-d)
     *   date_to     — filter invoice_date <= date (Y-m-d)
     *
     * Requirements: 14.1
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Invoice::with(['supplier', 'purchaseOrder', 'contract']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('invoice_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('invoice_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Notify the supplier user linked to the invoice.
     *
     * Silently swallows errors to avoid failing the main transaction.
     */
    private function notifySupplier(Invoice $invoice, string $eventType, string $titleKey, array $payload): void
    {
        try {
            $supplier = Supplier::withoutGlobalScopes()
                ->where('id', $invoice->supplier_id)
                ->whereNotNull('user_id')
                ->first();

            if (! $supplier) {
                return;
            }

            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $invoice->tenant_id,
                'user_id'    => $supplier->user_id,
                'event_type' => $eventType,
                'title'      => $payload['title'],
                'message'    => $payload['message'],
                'data'       => [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total_amount'   => $invoice->total_amount,
                    'currency'       => $invoice->currency,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('InvoiceService: failed to notify supplier', [
                'invoice_id'  => $invoice->id,
                'event_type'  => $eventType,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalise a numeric value to a BCMath-compatible string with SCALE decimals.
     */
    private function normalise(mixed $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
