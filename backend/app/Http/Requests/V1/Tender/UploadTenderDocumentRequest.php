<?php

namespace App\Http\Requests\V1\Tender;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for uploading a document to a tender.
 *
 * Accepted MIME types align with the FileManagementService allowed list.
 *
 * Requirements: 8.3, 23.1, 23.2
 */
class UploadTenderDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permission enforced at route level via role.check:tenders.create
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file'          => [
                'required',
                'file',
                'max:10240', // 10 MB
                'mimes:pdf,docx,xlsx,png,jpg,jpeg',
            ],
            'document_type' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
            'file.max'      => 'The file may not be larger than 10 MB.',
            'file.mimes'    => 'Accepted file types: PDF, DOCX, XLSX, PNG, JPG, JPEG.',
        ];
    }
}
