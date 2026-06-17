<?php

namespace App\Http\Requests\V1\Bid;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for uploading a document to an existing bid.
 *
 * Requirements: 8.4, 8.5
 */
class UploadBidDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level role check handles authorization.
    }

    public function rules(): array
    {
        return [
            'file'          => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:10240'],
            'document_type' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'          => 'A file is required.',
            'file.file'              => 'The uploaded value must be a file.',
            'file.mimes'             => 'The file must be a PDF, Word document, Excel spreadsheet, or image (PNG, JPG).',
            'file.max'               => 'The file size may not exceed 10 MB.',
            'document_type.required' => 'The document type is required.',
            'document_type.max'      => 'The document type may not exceed 100 characters.',
        ];
    }
}
