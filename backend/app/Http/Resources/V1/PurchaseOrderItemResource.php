<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PurchaseOrderItemResource — transforms a PurchaseOrderItem model into the API shape.
 *
 * Requirements: 10.2
 */
class PurchaseOrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'description'       => $this->description,
            'quantity'          => number_format((float) $this->quantity, 2, '.', ''),
            'received_quantity' => number_format((float) $this->received_quantity, 2, '.', ''),
            'unit_of_measure'   => $this->unit_of_measure,
            'unit_price'        => number_format((float) $this->unit_price, 2, '.', ''),
            'total_price'       => number_format((float) $this->total_price, 2, '.', ''),
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
