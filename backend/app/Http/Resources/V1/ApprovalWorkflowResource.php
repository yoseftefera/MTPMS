<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ApprovalWorkflowResource — transforms an ApprovalWorkflow model
 * into the standard API response shape, including its levels collection.
 *
 * Requirements: 6.2
 */
class ApprovalWorkflowResource extends JsonResource
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
            'tenant_id'     => $this->tenant_id,
            'name'          => $this->name,
            'document_type' => $this->document_type,
            'department_id' => $this->department_id,
            'department'    => $this->whenLoaded('department', fn () => new DepartmentResource($this->department)),
            'is_active'     => (bool) $this->is_active,
            'levels'        => ApprovalWorkflowLevelResource::collection($this->whenLoaded('levels', $this->levels)),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
