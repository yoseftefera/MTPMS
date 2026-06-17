<?php

namespace App\Http\Requests\V1\Budget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-budget request payload.
 *
 * Requirements: 13.1
 */
class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Finance_Officer only).
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'string', 'uuid'],
            'fiscal_year'   => ['required', 'integer', 'digits:4', 'min:2000', 'max:2099'],
            'total_amount'  => ['required', 'numeric', 'gt:0'],
            'currency'      => ['nullable', 'string', 'size:3', 'alpha'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.required' => 'Department ID is required.',
            'department_id.uuid'     => 'Department ID must be a valid UUID.',
            'fiscal_year.required'   => 'Fiscal year is required.',
            'fiscal_year.digits'     => 'Fiscal year must be a 4-digit year.',
            'fiscal_year.min'        => 'Fiscal year must be 2000 or later.',
            'fiscal_year.max'        => 'Fiscal year must be 2099 or earlier.',
            'total_amount.required'  => 'Total amount is required.',
            'total_amount.numeric'   => 'Total amount must be a number.',
            'total_amount.gt'        => 'Total amount must be greater than zero.',
            'currency.size'          => 'Currency must be a 3-letter ISO 4217 code.',
            'currency.alpha'         => 'Currency must contain only letters.',
        ];
    }
}
