<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * InventoryResource — transforms an Inventory model into the standard API shape.
 *
 * Requirements: 12.7, 12.8, 12.9
 */
class InventoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentStock     = (float) ($this->current_stock ?? 0);
        $reorderThreshold = (float) ($this->reorder_threshold ?? 0);
        $belowReorder     = $reorderThreshold > 0 && $currentStock < $reorderThreshold;

        return [
            'id'                => $this->id,
            'tenant_id'         => $this->tenant_id,
            'warehouse_id'      => $this->warehouse_id,
            'item_code'         => $this->item_code,
            'item_name'         => $this->item_name,
            'category'          => $this->category,
            'unit_of_measure'   => $this->unit_of_measure,
            'current_stock'     => number_format($currentStock, 2, '.', ''),
            'reorder_threshold' => number_format($reorderThreshold, 2, '.', ''),
            'unit_cost'         => number_format((float) ($this->unit_cost ?? 0), 2, '.', ''),

            // Computed stock level indicator
            'stock_level'       => $belowReorder ? 'below_reorder' : 'above_reorder',
            'is_below_reorder'  => $belowReorder,

            // Conditionally loaded relationships
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id'   => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
