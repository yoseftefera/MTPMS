<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AuditLogResource — serializes an AuditLog model into the standard API shape.
 *
 * before_data and after_data are cast to arrays on the model so they are
 * returned as objects/arrays rather than raw JSON strings.
 *
 * Requirements: 17.6, 17.7, 17.8
 */
class AuditLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'tenant_id'   => $this->tenant_id,
            'user_id'     => $this->user_id,
            'user_role'   => $this->user_role,
            'action'      => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id'   => $this->entity_id,
            'before_data' => $this->before_data,   // cast to array via model
            'after_data'  => $this->after_data,    // cast to array via model
            'ip_address'  => $this->ip_address,
            'request_id'  => $this->request_id,
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
