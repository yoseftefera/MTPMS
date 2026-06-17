<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BudgetResource — transforms a Budget model into the standard API response shape.
 *
 * Serializes all budget fields with monetary amounts as strings with exactly
 * 2 decimal places. Includes computed attributes `available_amount` and
 * `utilization_percentage` when present on the model.
 *
 * Requirements: 13.1, 13.8, 13.10
 */
class BudgetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Computed attributes may be set via setAttribute() by the service layer.
        $availableAmount      = $this->getAttribute('available_amount') ?? '0.00';
        $utilizationPercent   = $this->getAttribute('utilization_percent') ?? '0.00';
        $committedAmount      = $this->getAttribute('committed_amount') ?? null;

        return [
            'id'                     => $this->id,
            'tenant_id'              => $this->tenant_id,
            'department_id'          => $this->department_id,
            'department'             => $this->whenLoaded('department', fn () => new DepartmentResource($this->department)),
            'fiscal_year'            => (int) $this->fiscal_year,
            'currency'               => $this->currency,
            'total_amount'           => number_format((float) $this->total_amount, 2, '.', ''),
            'encumbered_amount'      => number_format((float) $this->encumbered_amount, 2, '.', ''),
            'spent_amount'           => number_format((float) $this->spent_amount, 2, '.', ''),
            'available_amount'       => number_format((float) $availableAmount, 2, '.', ''),
            'committed_amount'       => $committedAmount !== null ? number_format((float) $committedAmount, 2, '.', '') : null,
            'utilization_percentage' => number_format((float) $utilizationPercent, 2, '.', ''),
            'created_by'             => $this->created_by,
            'created_at'             => $this->created_at?->toIso8601String(),
            'updated_at'             => $this->updated_at?->toIso8601String(),
        ];
    }
}
