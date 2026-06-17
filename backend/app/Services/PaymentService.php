<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PaymentService — creates and manages payment records against invoices.
 *
 * Status flow:
 *  Invoice: approved → partially_paid → paid
 *  Payment: pending  → paid
 *
 * Supports partial payments: each call to recordPayment() adds to the
 * invoice's paid_amount. When paid_amount < total_amount the invoice moves
 * to partially_paid; once fully settled it transitions to paid.
 *
 * Requirements: 14.5, 14.6, 14.7, 14.8, 14.9, 14.10
 */
class PaymentService
{
    /**
     * BCMath decimal scale — matches DECIMAL(15,2) database columns.
     */
    private const SCALE = 2;

    // =========================================================================
    // createFromInvoice
    // =========================================================================

    /**
     * Create an initial Payment record when an invoice is approved.
     *
     * Sets the payment amount to the invoice total, due_date to the invoice
     * due_date, and status to pending.
     *
     * Requirements: 14.5
     */
    public function createFromInvoice(Invoice $invoice, User $actor): Payment
    {
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id'  => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'amount'     => $this->normalise($invoice->total_amount),
            'currency'   => $invoice->currency,
            'due_date'   => $invoice->due_date,
            'status'     => 'pending',
        ]);

        WriteAuditLogJob::dispatch(
            tenantId:   $invoice->tenant_id,
            userId:     $actor->id,
            userRole:   $actor->getRoleNames()->first() ?? 'finance_officer',
            actionType: 'payment.created',
            entityType: 'payment',
            entityId:   $payment->id,
            before:     null,
            after:      [
                'invoice_id' => $invoice->id,
                'amount'     => $payment->amount,
                'currency'   => $payment->currency,
                'due_date'   => $payment->due_date instanceof \DateTimeInterface
                    ? $payment->due_date->format('Y-m-d')
                    : (string) $payment->due_date,
                'status'     => 'pending',
            ],
            ipAddress:  '0.0.0.0',
            requestId:  null,
        )->onQueue('default');

        return $payment;
    }

    // =========================================================================
    // recordPayment
    // =========================================================================

    /**
     * Record a payment (full or partial) against an invoice.
     *
     * Steps:
     *  1. Validate amountPaid > 0.
     *  2. Add amountPaid to invoice.paid_amount using bcadd.
     *  3. If paid_amount < total_amount → invoice.status = partially_paid.
     *     Else → invoice.status = paid; payment.status = paid.
     *  4. Set payment.payment_date = now(), payment.processed_by = actor->id.
     *  5. Dispatch audit log.
     *  6. Notify supplier user.
     *
     * Requirements: 14.6, 14.8, 14.9
     *
     * @throws InvalidArgumentException  when amountPaid ≤ 0
     */
    public function recordPayment(
        Payment $payment,
        string  $amountPaid,
        string  $paymentMethod,
        ?string $paymentReference,
        User    $actor,
    ): Payment {
        $amount = $this->normalise($amountPaid);

        if (bccomp($amount, '0.00', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($payment, $amount, $paymentMethod, $paymentReference, $actor) {
            /** @var Invoice $invoice */
            $invoice = Invoice::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($payment->invoice_id);

            $before = [
                'invoice_status' => $invoice->status,
                'paid_amount'    => $invoice->paid_amount,
                'payment_status' => $payment->status,
            ];

            // Accumulate paid_amount on invoice
            $newPaidAmount = bcadd(
                $this->normalise($invoice->paid_amount ?? '0.00'),
                $amount,
                self::SCALE,
            );

            $invoice->paid_amount = $newPaidAmount;

            if (bccomp($newPaidAmount, $this->normalise($invoice->total_amount), self::SCALE) < 0) {
                $invoice->status = 'partially_paid';
            } else {
                $invoice->status  = 'paid';
                $payment->status = 'paid';
            }

            $invoice->save();

            $payment->payment_date      = now();
            $payment->processed_by      = $actor->id;
            $payment->payment_method    = $paymentMethod;
            $payment->payment_reference = $paymentReference;
            $payment->save();

            WriteAuditLogJob::dispatch(
                tenantId:   $invoice->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'finance_officer',
                actionType: 'payment.recorded',
                entityType: 'payment',
                entityId:   $payment->id,
                before:     $before,
                after:      [
                    'invoice_status'     => $invoice->status,
                    'paid_amount'        => $newPaidAmount,
                    'payment_status'     => $payment->status,
                    'payment_method'     => $paymentMethod,
                    'payment_reference'  => $paymentReference,
                    'payment_date'       => now()->toIso8601String(),
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            $this->notifySupplier($invoice, $payment);

            return $payment->fresh();
        });
    }

    // =========================================================================
    // getPaymentSchedule
    // =========================================================================

    /**
     * Return invoices with status IN (approved, partially_paid) together with
     * their computed amount_due (total_amount − paid_amount) and due_date.
     *
     * Supports optional filters:
     *   supplier_id — filter by supplier UUID
     *   date_from   — filter due_date >= date (Y-m-d)
     *   date_to     — filter due_date <= date (Y-m-d)
     *
     * Requirements: 14.7
     */
    public function getPaymentSchedule(array $filters = []): array
    {
        $query = Invoice::with(['supplier', 'purchaseOrder', 'contract'])
            ->whereIn('status', ['approved', 'partially_paid']);

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('due_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('due_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('due_date')->get()->map(function (Invoice $invoice) {
            $amountDue = bcsub(
                $this->normalise($invoice->total_amount),
                $this->normalise($invoice->paid_amount ?? '0.00'),
                self::SCALE,
            );

            return [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'supplier_id'    => $invoice->supplier_id,
                'supplier_name'  => $invoice->supplier?->organization_name,
                'total_amount'   => $this->normalise($invoice->total_amount),
                'paid_amount'    => $this->normalise($invoice->paid_amount ?? '0.00'),
                'amount_due'     => $amountDue,
                'currency'       => $invoice->currency,
                'due_date'       => $invoice->due_date instanceof \DateTimeInterface
                    ? $invoice->due_date->format('Y-m-d')
                    : (string) $invoice->due_date,
                'status'         => $invoice->status,
            ];
        })->all();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Notify the supplier user that a payment has been processed.
     *
     * Requirements: 14.6
     */
    private function notifySupplier(Invoice $invoice, Payment $payment): void
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
                'event_type' => 'payment_processed',
                'title'      => "Payment Processed for Invoice {$invoice->invoice_number}",
                'message'    => "A payment has been processed for your invoice {$invoice->invoice_number}. "
                             . "Invoice status: {$invoice->status}.",
                'data'       => [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'payment_id'     => $payment->id,
                    'amount'         => $payment->amount,
                    'currency'       => $payment->currency,
                    'invoice_status' => $invoice->status,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('PaymentService: failed to notify supplier on payment', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
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
