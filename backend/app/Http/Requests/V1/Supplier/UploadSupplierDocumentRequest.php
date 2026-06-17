<?php

namespace App\Http\Requests\V1\Supplier;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the supplier compliance document upload payload.
 *
 * Allowed MIME types: PDF, Word (.doc/.docx), Excel (.xls/.xlsx), PNG, JPEG.
 * Maximum file size: 10 MB (enforced again in the service layer for defence-in-depth).
 *
 * Requirements: 7.10
 */
class UploadSupplierDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by role.check middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => [
                'required',
                'string',
                'in:tin_certificate,vat_certificate,business_license,performance_bond,other',
            ],
            'file' => [
                'required',
                'file',
                'max:10240',   // 10 MB in kilobytes
                'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg',
            ],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => 'The document type is required.',
            'document_type.in'       => 'Invalid document type. Allowed: tin_certificate, vat_certificate, business_license, performance_bond, other.',
            'file.required'          => 'A file is required.',
            'file.file'              => 'The uploaded item must be a file.',
            'file.max'               => 'The file must not exceed 10 MB.',
            'file.mimes'             => 'Only PDF, Word, Excel, PNG, and JPEG files are allowed.',
            'expires_at.date'        => 'The expiry date must be a valid date.',
            'expires_at.after'       => 'The expiry date must be in the future.',
        ];
    }
}
