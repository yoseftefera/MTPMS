<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ContractDocumentResource — transforms a ContractDocument into the API shape.
 *
 * Requirements: 11.2, 11.7
 */
class ContractDocumentResource extends JsonResource
{
    /**
     * Human-readable labels for each document type.
     */
    private const TYPE_LABELS = [
        'performance_bond'  => 'Performance Bond',
        'signed_contract'   => 'Signed Contract',
        'amendment'         => 'Amendment',
        'other'             => 'Other',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'contract_id'    => $this->contract_id,
            'document_type'  => $this->document_type,
            'type_label'     => self::TYPE_LABELS[$this->document_type] ?? ucwords(str_replace('_', ' ', $this->document_type)),
            'file_name'      => $this->file_name,
            'file_path'      => $this->file_path,
            'uploaded_by'    => $this->whenLoaded('uploadedBy', fn () => [
                'id'    => $this->uploadedBy->id,
                'name'  => $this->uploadedBy->name,
                'email' => $this->uploadedBy->email,
            ]),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
