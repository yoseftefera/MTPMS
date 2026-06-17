<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ApprovalResource — transforms an Approval model into the standard API
 * response shape, including approver name and level details.
 *
 * Requirements: 6.3, 6.4, 6.5
 */
class ApprovalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'workflow_id'   => $this->workflow_id,
            'level_id'      => $this->level_id,
            'level_order'   => $this->whenLoaded('level', fn () => (int) $this->level->level_order),
            'document_type' => $this->document_type,
            'document_id'   => $this->document_id,
            'approver_id'   => $this->approver_id,
            'approver_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'action'        => $this->action,
            'comment'       => $this->comment,
            'acted_at'      => $this->acted_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
