<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DepartmentResource — transforms a Department model into the standard API response shape.
 *
 * Includes identity, tenant context, hierarchy (parent/children), and status.
 * Children are recursively rendered using the same resource.
 *
 * Requirements: 4.3, 4.4, 4.5
 */
class DepartmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'tenant_id'  => $this->tenant_id,
            'name'       => $this->name,
            'code'       => $this->code,
            'status'     => $this->status,
            'parent_id'  => $this->parent_id,
            'parent'     => $this->whenLoaded('parent', fn () => new DepartmentResource($this->parent)),
            'children'   => $this->whenLoaded('children', fn () => DepartmentResource::collection($this->children)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
