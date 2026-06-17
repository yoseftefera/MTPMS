<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BidEvaluationResource — transforms a BidEvaluation model into the standard
 * API response shape (used when a Committee_Member submits a score).
 *
 * - `score` is serialized as a decimal string with 2 decimal places.
 * - Related `criteria` and `evaluator` are conditionally included when loaded.
 *
 * Requirements: 9.2
 */
class BidEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'tenant_id'    => $this->tenant_id,
            'bid_id'       => $this->bid_id,
            'criteria_id'  => $this->criteria_id,
            'evaluator_id' => $this->evaluator_id,
            'score'        => number_format((float) $this->score, 2, '.', ''),
            'comment'      => $this->comment,
            'is_finalized' => (bool) $this->is_finalized,

            // Conditionally loaded relationships
            'criteria' => $this->whenLoaded('criteria', fn () => [
                'id'     => $this->criteria->id,
                'name'   => $this->criteria->name,
                'weight' => number_format((float) $this->criteria->weight, 2, '.', ''),
            ]),
            'evaluator' => $this->whenLoaded('evaluator', fn () => [
                'id'   => $this->evaluator->id,
                'name' => $this->evaluator->name,
            ]),
            'bid' => $this->whenLoaded('bid', fn () => [
                'id'           => $this->bid->id,
                'supplier_id'  => $this->bid->supplier_id,
                'total_amount' => number_format((float) $this->bid->total_amount, 2, '.', ''),
                'status'       => $this->bid->status,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
