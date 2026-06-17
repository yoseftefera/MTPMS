<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BidEvaluationCriteriaResource — transforms a BidEvaluationCriteria model
 * into the standard API response shape.
 *
 * - `weight` and `max_score` are serialized as decimal strings with 2 decimal places.
 *
 * Requirements: 9.1
 */
class BidEvaluationCriteriaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'tenant_id'   => $this->tenant_id,
            'tender_id'   => $this->tender_id,
            'name'        => $this->name,
            'weight'      => number_format((float) $this->weight, 2, '.', ''),
            'max_score'   => number_format((float) $this->max_score, 2, '.', ''),
            'description' => $this->description,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
