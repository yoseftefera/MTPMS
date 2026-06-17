<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GoodsReceiptResource — transforms a GoodsReceipt model into the standard API shape.
 *
 * Requirements: 12.1, 12.2, 12.3, 12.6
 */
class GoodsReceiptResource extends JsonResource
{
    private const STATUS_LABELS = [
        'draft'              => 'Draft',
        'pending_inspection' => 'Pending Inspection',
        'under_inspection'   => 'Under Inspection',
        'accepted'           => 'Accepted',
        'partially_accepted' => 'Partially Accepted',
        'rejected'           => 'Rejected',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'tenant_id'             => $this->tenant_id,
            'grn_number'            => $this->grn_number,
            'purchase_order_id'     => $this->purchase_order_id,
            'warehouse_id'          => $this->warehouse_id,
            'delivery_note_number'  => $this->delivery_note_number,
            'status'                => $this->status,
            'status_label'          => self::STATUS_LABELS[$this->status] ?? ucfirst($this->status),
            'received_by'           => $this->received_by,
            'received_at'           => $this->received_at?->toIso8601String(),
            'assigned_inspectors'   => $this->assigned_inspectors ?? [],

            // Delivery note download URL — available once accepted/partially_accepted
            'delivery_note_url'     => $this->whenNotNull(
                in_array($this->status, ['accepted', 'partially_accepted'], true) ? true : null,
                fn () => route('api.goods-receipts.delivery-note', ['goodsReceipt' => $this->id], absolute: false),
            ),

            // Conditionally loaded relationships
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id'        => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
                'status'    => $this->purchaseOrder->status,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id'   => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'received_by_user' => $this->whenLoaded('receivedBy', fn () => [
                'id'    => $this->receivedBy->id,
                'name'  => $this->receivedBy->name,
                'email' => $this->receivedBy->email,
            ]),
            'items' => $this->whenLoaded('items', fn () =>
                GoodsReceiptItemResource::collection($this->items)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
