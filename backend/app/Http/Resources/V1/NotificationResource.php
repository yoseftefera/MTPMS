<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NotificationResource — serialises a Notification model to the standard API shape.
 *
 * Note: Notification records only carry `created_at` (no `updated_at`).
 *
 * Requirements: 15.4, 15.7
 */
class NotificationResource extends JsonResource
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
            'user_id'    => $this->user_id,
            'event_type' => $this->event_type,
            'title'      => $this->title,
            'message'    => $this->message,
            'data'       => $this->data ?? [],
            'is_read'    => (bool) $this->is_read,
            'read_at'    => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
