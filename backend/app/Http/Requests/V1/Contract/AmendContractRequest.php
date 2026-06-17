<?php

namespace App\Http\Requests\V1\Contract;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the amend-contract payload.
 *
 * Requirements: 11.5, 11.6
 */
class AmendContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'        => ['required', 'string', 'min:10', 'max:2000'],
            'title'         => ['sometimes', 'string', 'max:500'],
            'scope'         => ['sometimes', 'string', 'max:5000'],
            'total_value'   => ['sometimes', 'numeric', 'gt:0'],
            'end_date'      => ['sometimes', 'date'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'An amendment reason is required.',
            'reason.min'      => 'The amendment reason must be at least 10 characters.',
            'reason.max'      => 'The amendment reason may not exceed 2000 characters.',
            'title.max'       => 'The contract title may not exceed 500 characters.',
            'scope.max'       => 'The contract scope may not exceed 5000 characters.',
            'total_value.numeric' => 'The total value must be a number.',
            'total_value.gt'      => 'The total value must be greater than zero.',
            'end_date.date'       => 'The end date must be a valid date.',
        ];
    }
}
