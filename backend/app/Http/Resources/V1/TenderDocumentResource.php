<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TenderDocumentResource — transforms a TenderDocument model into the standard API response shape.
 *
 * Requirements: 8.1, 8.3
 */
class TenderDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'tender_id'     => $this->tender_id,
            'document_type' => $this->document_type,
            'file_name'     => $this->file_name,
            'file_path'     => $this->file_path,
            'uploaded_by'   => $this->uploaded_by,
            'uploader'      => $this->whenLoaded('uploadedBy', fn () => [
                'id'   => $this->uploadedBy->id,
                'name' => $this->uploadedBy->name,
            ]),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
