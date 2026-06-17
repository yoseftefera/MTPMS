<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * InvoiceResource — transforms an Invoice model into the standard API shape.
 *
 * - Monetary amounts serialized as strings with 2 decimal places.
 * - Conditionally includes supplier, purchaseOrder, contract, and payments when loaded.
 * - Provides a human-readable `status_label` and a `balance_due` computed field.
 *
 * Requirements: 14.1, 14.2, 14.4
 */
class InvoiceResource extends JsonResource
{
    private const STATUS_LABELS = [
        'pending_approval' => 'Pending Approval',
        'approved'         => 'Approved',
        'rejected'         => 'Rejected',
        'partially_paid'   => 'Partially Paid',
        'paid'             => 'Paid',
    ];

    public function toArray(Request $request): array
    {
        $totalAmount = (float) ($this->total_amount ?? 0);
        $paidAmount  = (float) ($this->paid_amount ?? 0);
        $balanceDue  = max(0.0, $totalAmount - $paidAmount);

        return [
            'id'             => $this->id,
            'tenant_id'      => $this->tenant_id,
            'invoice_number' => $this->invoice_number,
            'status'         => $this->status,
            'status_label'   => self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status),
            'currency'       => $this->currency,
            'total_amount'   => number_format($totalAmount, 2, '.', ''),
            'paid_amount'    => number_format($paidAmount, 2, '.', ''),
            'balance_due'    => number_format($balanceDue, 2, '.', ''),

            // Dates
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date'     => $this->due_date?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),

            // Reason fields
            'rejection_reason' => $this->rejection_reason ?? null,

            // Foreign key IDs
            'supplier_id'       => $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id,
            'contract_id'       => $this->contract_id,

            // Conditionally loaded relationships
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id'                => $this->supplier->id,
                'organization_name' => $this->supplier->organization_name,
                'contact_email'     => $this->supplier->contact_email,
            ]),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id'        => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
            ]),
            'contract' => $this->whenLoaded('contract', fn () => [
                'id'              => $this->contract->id,
                'contract_number' => $this->contract->contract_number,
            ]),
            'payments' => $this->whenLoaded('payments', fn () =>
                PaymentResource::collection($this->payments)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
