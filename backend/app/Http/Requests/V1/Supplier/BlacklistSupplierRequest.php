<?php

namespace App\Http\Requests\V1\Supplier;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the blacklist-supplier payload.
 *
 * A documented reason is mandatory for auditing purposes.
 *
 * Requirements: 7.4, 7.5
 */
class BlacklistSupplierRequest extends FormRequest
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
            'reason.required' => 'A blacklist reason is required.',
            'reason.string'   => 'The reason must be a string.',
            'reason.min'      => 'The blacklist reason must be at least 10 characters.',
            'reason.max'      => 'The blacklist reason may not exceed 2000 characters.',
        ];
    }
}
