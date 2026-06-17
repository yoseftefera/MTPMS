<?php

namespace App\Http\Requests\V1\Supplier;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the reject-supplier-registration payload.
 *
 * Requirements: 7.2
 */
class RejectSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by role.check middleware (Procurement_Officer).
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required.',
            'reason.string'   => 'The reason must be a string.',
            'reason.min'      => 'The rejection reason must be at least 10 characters.',
            'reason.max'      => 'The rejection reason may not exceed 2000 characters.',
        ];
    }
}
