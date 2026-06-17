<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GoodsReceiptItemResource — transforms a GoodsReceiptItem model.
 *
 * Requirements: 12.1, 12.3
 */
class GoodsReceiptItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'goods_receipt_id'        => $this->goods_receipt_id,
            'purchase_order_item_id'  => $this->purchase_order_item_id,
            'description'             => $this->description,
            'quantity_received'       => number_format((float) $this->quantity_received, 2, '.', ''),
            'quantity_accepted'       => number_format((float) $this->quantity_accepted, 2, '.', ''),
            'quantity_rejected'       => number_format((float) $this->quantity_rejected, 2, '.', ''),
            'rejection_reason'        => $this->rejection_reason,
            'status'                  => $this->status,
            'inspection_votes'        => $this->inspection_votes ?? [],
            'created_at'              => $this->created_at?->toIso8601String(),
            'updated_at'              => $this->updated_at?->toIso8601String(),
        ];
    }
}
