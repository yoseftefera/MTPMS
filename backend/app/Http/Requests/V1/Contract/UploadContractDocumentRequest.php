<?php

namespace App\Http\Requests\V1\Contract;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the upload-contract-document payload.
 *
 * Requirements: 11.2, 11.7
 */
class UploadContractDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:performance_bond,signed_contract,amendment,other'],
            'file'          => ['required', 'file', 'mimes:pdf,doc,docx,xlsx,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => 'The document type is required.',
            'document_type.in'       => 'Document type must be one of: performance_bond, signed_contract, amendment, other.',
            'file.required'          => 'A file is required.',
            'file.mimes'             => 'The file must be a PDF, Word document, Excel spreadsheet, or image.',
            'file.max'               => 'The file may not exceed 10 MB.',
        ];
    }
}
