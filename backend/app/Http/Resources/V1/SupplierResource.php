<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SupplierResource — transforms a Supplier model into the standard API response shape.
 *
 * Performance metrics are serialised as decimal strings with exactly 2 decimal places
 * to match the DECIMAL(5,2) database columns.
 *
 * Requirements: 7.1, 7.4, 7.6, 7.7, 7.10
 */
class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'tenant_id'               => $this->tenant_id,
            'user_id'                 => $this->user_id,
            'organization_name'       => $this->organization_name,
            'contact_name'            => $this->contact_name,
            'contact_email'           => $this->contact_email,
            'contact_phone'           => $this->contact_phone,
            'business_category'       => $this->business_category,
            'status'                  => $this->status,

            // Blacklist details — only populated when status = 'blacklisted'
            'blacklist_reason'        => $this->blacklist_reason,
            'blacklisted_by'          => $this->blacklisted_by,
            'blacklisted_by_user'     => $this->whenLoaded('blacklistedBy', fn () => [
                'id'   => $this->blacklistedBy->id,
                'name' => $this->blacklistedBy->name,
            ]),
            'blacklisted_at'          => $this->blacklisted_at?->toIso8601String(),

            // Performance metrics — DECIMAL(5,2) stored as strings
            'on_time_delivery_rate'   => number_format((float) $this->on_time_delivery_rate, 2, '.', ''),
            'quality_acceptance_rate' => number_format((float) $this->quality_acceptance_rate, 2, '.', ''),

            // Relationships — only serialised when eager-loaded
            'documents'   => $this->whenLoaded('documents',
                fn () => SupplierDocumentResource::collection($this->documents)
            ),
            'performances' => $this->whenLoaded('performances',
                fn () => SupplierPerformanceResource::collection($this->performances)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
