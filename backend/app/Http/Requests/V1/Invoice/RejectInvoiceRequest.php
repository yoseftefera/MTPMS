<?php

namespace App\Http\Requests\V1\Invoice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/invoices/{invoice}/reject
 *
 * Requirements: 14.4
 */
class RejectInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required.',
            'reason.min'      => 'The rejection reason cannot be empty.',
        ];
    }
}
