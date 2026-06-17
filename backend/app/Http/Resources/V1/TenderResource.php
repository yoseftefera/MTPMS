<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TenderResource — transforms a Tender model into the standard API response shape.
 *
 * - `estimated_value` is serialized as a decimal string with exactly 2 decimal places.
 * - Documents, bids, and createdBy are only included when eager-loaded.
 *
 * Requirements: 8.1, 8.2, 8.3, 8.6, 8.8, 8.9, 8.10
 */
class TenderResource extends JsonResource
{
    /**
     * Human-readable labels for each tender status.
     */
    private const STATUS_LABELS = [
        'draft'     => 'Draft',
        'published' => 'Published',
        'closed'    => 'Closed',
        'awarded'   => 'Awarded',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Human-readable labels for each tender type.
     */
    private const TYPE_LABELS = [
        'open'          => 'Open Tender',
        'restricted'    => 'Restricted Tender',
        'single_source' => 'Single Source',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'tenant_id'            => $this->tenant_id,
            'reference_number'     => $this->reference_number,
            'title'                => $this->title,
            'description'          => $this->description,
            'category'             => $this->category,
            'tender_type'          => $this->tender_type,
            'tender_type_label'    => self::TYPE_LABELS[$this->tender_type] ?? ucfirst($this->tender_type),
            'estimated_value'      => number_format((float) $this->estimated_value, 2, '.', ''),
            'status'               => $this->status,
            'status_label'         => self::STATUS_LABELS[$this->status] ?? ucfirst($this->status),
            'submission_deadline'  => $this->submission_deadline?->toIso8601String(),
            'published_at'         => $this->published_at?->toIso8601String(),
            'cancellation_reason'  => $this->cancellation_reason,
            'evaluation_status'    => $this->evaluation_status ?? null,
            'winning_bid_id'       => $this->winning_bid_id,
            'winner_justification' => $this->winner_justification,

            'created_by'           => $this->created_by,
            'created_by_user'      => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),

            // Documents — only present when eager-loaded
            'documents' => $this->whenLoaded(
                'documents',
                fn () => TenderDocumentResource::collection($this->documents)
            ),

            // Bids summary — only present when eager-loaded
            'bids_count' => $this->whenLoaded('bids', fn () => $this->bids->count()),
            'bids'       => $this->whenLoaded('bids', fn () => $this->bids->map(fn ($bid) => [
                'id'           => $bid->id,
                'supplier_id'  => $bid->supplier_id,
                'total_amount' => number_format((float) $bid->total_amount, 2, '.', ''),
                'status'       => $bid->status,
                'submitted_at' => $bid->submitted_at?->toIso8601String(),
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
