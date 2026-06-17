<?php

namespace App\Http\Requests\V1\PurchaseRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a file attachment upload for a purchase request.
 *
 * Allowed types: PDF, DOCX, XLSX, PNG, JPG, JPEG
 * Max size: 10 MB (10240 KB)
 *
 * Requirements: 5.10
 */
class AttachDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,docx,xlsx,png,jpg,jpeg',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
            'file.file'     => 'The uploaded value must be a file.',
            'file.mimes'    => 'Allowed file types are: PDF, DOCX, XLSX, PNG, JPG, JPEG.',
            'file.max'      => 'The file may not exceed 10 MB.',
        ];
    }
}
