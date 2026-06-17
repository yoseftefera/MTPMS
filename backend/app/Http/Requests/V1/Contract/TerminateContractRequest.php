<?php

namespace App\Http\Requests\V1\Contract;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the terminate-contract payload.
 *
 * Requirements: 11.10
 */
class TerminateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'reason.required' => 'A termination reason is required.',
            'reason.min'      => 'The termination reason must be at least 10 characters.',
            'reason.max'      => 'The termination reason may not exceed 2000 characters.',
        ];
    }
}
