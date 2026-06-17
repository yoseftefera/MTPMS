<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PaymentResource — transforms a Payment model into the standard API shape.
 *
 * - Monetary amounts serialized as strings with 2 decimal places.
 * - Conditionally includes invoice and processedBy when loaded.
 *
 * Requirements: 14.5, 14.6, 14.8
 */
class PaymentResource extends JsonResource
{
    private const STATUS_LABELS = [
        'pending'  => 'Pending',
        'paid'     => 'Paid',
        'overdue'  => 'Overdue',
        'cancelled'=> 'Cancelled',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'tenant_id'          => $this->tenant_id,
            'invoice_id'         => $this->invoice_id,
            'status'             => $this->status,
            'status_label'       => self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status),
            'currency'           => $this->currency,
            'amount'             => number_format((float) ($this->amount ?? 0), 2, '.', ''),
            'payment_method'     => $this->payment_method,
            'payment_reference'  => $this->payment_reference,
            'notes'              => $this->notes,
            'processed_by'       => $this->processed_by,

            // Dates
            'payment_date' => $this->payment_date?->toDateString(),
            'due_date'     => $this->due_date?->toDateString(),

            // Conditionally loaded relationships
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id'             => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'total_amount'   => number_format((float) $this->invoice->total_amount, 2, '.', ''),
                'paid_amount'    => number_format((float) $this->invoice->paid_amount, 2, '.', ''),
                'status'         => $this->invoice->status,
            ]),
            'processor' => $this->whenLoaded('processedBy', fn () => [
                'id'    => $this->processedBy->id,
                'name'  => $this->processedBy->name,
                'email' => $this->processedBy->email,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
