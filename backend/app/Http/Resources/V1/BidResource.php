<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BidResource — transforms a Bid model into the standard API response shape.
 *
 * - Monetary amounts (total_amount, weighted_score) serialized as decimal strings.
 * - Conditionally includes tender, supplier, documents, and evaluations when loaded.
 *
 * Requirements: 8.4, 8.5, 8.7
 */
class BidResource extends JsonResource
{
    /**
     * Human-readable labels for each bid status value.
     */
    private const STATUS_LABELS = [
        'draft'            => 'Draft',
        'submitted'        => 'Submitted',
        'under_evaluation' => 'Under Evaluation',
        'won'              => 'Won',
        'lost'             => 'Lost',
        'disqualified'     => 'Disqualified',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'tenant_id'       => $this->tenant_id,
            'tender_id'       => $this->tender_id,
            'supplier_id'     => $this->supplier_id,
            'total_amount'    => number_format((float) $this->total_amount, 2, '.', ''),
            'currency'        => $this->currency,
            'delivery_days'   => $this->delivery_days,
            'technical_notes' => $this->technical_notes,
            'status'          => $this->status,
            'status_label'    => self::STATUS_LABELS[$this->status] ?? ucfirst($this->status),
            'weighted_score'  => $this->weighted_score !== null
                ? number_format((float) $this->weighted_score, 4, '.', '')
                : null,
            'submitted_at'    => $this->submitted_at?->toIso8601String(),

            // Conditionally loaded relationships
            'tender' => $this->whenLoaded('tender', fn () => [
                'id'                  => $this->tender->id,
                'reference_number'    => $this->tender->reference_number,
                'title'               => $this->tender->title,
                'category'            => $this->tender->category,
                'submission_deadline' => $this->tender->submission_deadline?->toIso8601String(),
                'status'              => $this->tender->status,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id'                => $this->supplier->id,
                'organization_name' => $this->supplier->organization_name,
                'contact_name'      => $this->supplier->contact_name,
                'contact_email'     => $this->supplier->contact_email,
                'business_category' => $this->supplier->business_category,
                'status'            => $this->supplier->status,
            ]),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($doc) => [
                'id'            => $doc->id,
                'document_type' => $doc->document_type,
                'file_name'     => $doc->file_name,
                'file_path'     => $doc->file_path,
                'uploaded_by'   => $doc->uploaded_by,
                'created_at'    => $doc->created_at?->toIso8601String(),
            ])),
            'evaluations' => $this->whenLoaded('evaluations', fn () => $this->evaluations->map(fn ($ev) => [
                'id'           => $ev->id,
                'criteria_id'  => $ev->criteria_id,
                'evaluator_id' => $ev->evaluator_id,
                'score'        => $ev->score,
                'comment'      => $ev->comment,
                'is_finalized' => $ev->is_finalized,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
