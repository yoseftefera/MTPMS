<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PurchaseOrderResource — transforms a PurchaseOrder model into the standard API shape.
 *
 * - Monetary amounts serialized as strings with 2 decimal places.
 * - Conditionally includes items, supplier, department, and creator when loaded.
 * - Provides a human-readable `status_label` computed field.
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.9
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * Human-readable labels for each PO status value.
     */
    private const STATUS_LABELS = [
        'draft'              => 'Draft',
        'issued'             => 'Issued',
        'accepted'           => 'Accepted',
        'rejected'           => 'Rejected',
        'cancelled'          => 'Cancelled',
        'overdue'            => 'Overdue',
        'partially_received' => 'Partially Received',
        'fully_received'     => 'Fully Received',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'tenant_id'     => $this->tenant_id,
            'po_number'     => $this->po_number,
            'status'        => $this->status,
            'status_label'  => self::STATUS_LABELS[$this->status] ?? ucfirst($this->status),
            'currency'      => $this->currency,
            'total_amount'  => number_format((float) $this->total_amount, 2, '.', ''),

            // Flags
            'pending_supplier_acknowledgment' => (bool) ($this->pending_supplier_acknowledgment ?? false),

            // Dates
            'required_delivery_date' => $this->required_delivery_date?->toDateString(),
            'issued_at'              => $this->issued_at?->toIso8601String(),
            'accepted_at'            => $this->accepted_at?->toIso8601String(),

            // Reason fields
            'rejection_reason'   => $this->rejection_reason ?? null,
            'cancellation_reason'=> $this->cancellation_reason ?? null,

            // Address
            'delivery_address' => $this->delivery_address,

            // Foreign key IDs
            'purchase_request_id' => $this->purchase_request_id,
            'bid_id'              => $this->bid_id,
            'supplier_id'         => $this->supplier_id,
            'department_id'       => $this->department_id,
            'created_by'          => $this->created_by,

            // Conditionally loaded relationships
            'supplier'   => $this->whenLoaded('supplier', fn () => [
                'id'                => $this->supplier->id,
                'organization_name' => $this->supplier->organization_name,
                'contact_email'     => $this->supplier->contact_email,
                'contact_phone'     => $this->supplier->contact_phone,
            ]),
            'department' => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code ?? null,
            ]),
            'creator' => $this->whenLoaded('createdBy', fn () => [
                'id'    => $this->createdBy->id,
                'name'  => $this->createdBy->name,
                'email' => $this->createdBy->email,
            ]),
            'items' => $this->whenLoaded('items', fn () =>
                PurchaseOrderItemResource::collection($this->items)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
