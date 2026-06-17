<?php

namespace App\Http\Requests\V1\Budget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the update-budget request payload.
 *
 * Requirements: 13.1
 */
class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Finance_Officer only).
        return true;
    }

    public function rules(): array
    {
        return [
            'total_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'currency'     => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
        ];
    }

    public function messages(): array
    {
        return [
            'total_amount.numeric' => 'Total amount must be a number.',
            'total_amount.gt'      => 'Total amount must be greater than zero.',
            'currency.size'        => 'Currency must be a 3-letter ISO 4217 code.',
            'currency.alpha'       => 'Currency must contain only letters.',
        ];
    }
}
