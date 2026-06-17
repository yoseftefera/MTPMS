<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ContractAmendmentResource — transforms a ContractAmendment into the API shape.
 *
 * Requirements: 11.5, 11.6
 */
class ContractAmendmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'contract_id'      => $this->contract_id,
            'amendment_number' => $this->amendment_number,
            'reason'           => $this->reason,
            'changes'          => $this->changes,
            'amended_by'       => $this->whenLoaded('amendedBy', fn () => [
                'id'    => $this->amendedBy->id,
                'name'  => $this->amendedBy->name,
                'email' => $this->amendedBy->email,
            ]),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
