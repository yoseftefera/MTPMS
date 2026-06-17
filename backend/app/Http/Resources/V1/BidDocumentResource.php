<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BidDocumentResource — transforms a BidDocument model into the standard API response shape.
 *
 * Requirements: 8.4, 8.5
 */
class BidDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'bid_id'        => $this->bid_id,
            'document_type' => $this->document_type,
            'file_name'     => $this->file_name,
            'file_path'     => $this->file_path,
            'uploaded_by'   => $this->uploaded_by,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
