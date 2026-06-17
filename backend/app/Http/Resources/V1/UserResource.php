<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource — transforms a User model into the standard API response shape.
 *
 * Includes identity, tenant context, role, and permissions.
 * Used by AuthController and UserController responses.
 *
 * Requirements: 2.1, 4.1
 */
class UserResource extends JsonResource
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
            'name'          => $this->name,
            'email'         => $this->email,
            'tenant_id'     => $this->tenant_id,
            'department_id' => $this->department_id,
            'status'        => $this->status,
            'phone'         => $this->phone,
            'avatar'        => $this->avatar,
            'role'          => $this->getRoleNames()->first(),
            'permissions'   => $this->getAllPermissions()->pluck('name')->values()->toArray(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
