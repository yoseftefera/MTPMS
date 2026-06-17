<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SupplierPerformanceResource — transforms a SupplierPerformance model.
 *
 * Requirements: 7.6
 */
class SupplierPerformanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'supplier_id'    => $this->supplier_id,
            'metric_type'    => $this->metric_type,
            'value'          => number_format((float) $this->value, 4, '.', ''),
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'recorded_at'    => $this->recorded_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
