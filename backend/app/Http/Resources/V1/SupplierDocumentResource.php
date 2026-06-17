<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SupplierDocumentResource — transforms a SupplierDocument model.
 *
 * Requirements: 7.10
 */
class SupplierDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'supplier_id'   => $this->supplier_id,
            'document_type' => $this->document_type,
            'file_name'     => $this->file_name,
            'file_path'     => $this->file_path,
            'version'       => (int) $this->version,
            'expires_at'    => $this->expires_at?->toDateString(),
            'uploaded_by'   => $this->uploaded_by,
            'uploader'      => $this->whenLoaded('uploadedBy', fn () => [
                'id'   => $this->uploadedBy->id,
                'name' => $this->uploadedBy->name,
            ]),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
