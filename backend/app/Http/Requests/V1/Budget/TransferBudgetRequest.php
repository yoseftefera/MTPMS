<?php

namespace App\Http\Requests\V1\Budget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the budget-transfer request payload.
 *
 * Requirements: 13.8
 */
class TransferBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Finance_Officer only).
        return true;
    }

    public function rules(): array
    {
        return [
            'from_budget_id' => ['required', 'string', 'uuid'],
            'to_budget_id'   => ['required', 'string', 'uuid', 'different:from_budget_id'],
            'amount'         => ['required', 'numeric', 'gt:0'],
            'note'           => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_budget_id.required'  => 'Source budget ID is required.',
            'from_budget_id.uuid'      => 'Source budget ID must be a valid UUID.',
            'to_budget_id.required'    => 'Destination budget ID is required.',
            'to_budget_id.uuid'        => 'Destination budget ID must be a valid UUID.',
            'to_budget_id.different'   => 'Source and destination budgets must be different.',
            'amount.required'          => 'Transfer amount is required.',
            'amount.numeric'           => 'Transfer amount must be a number.',
            'amount.gt'                => 'Transfer amount must be greater than zero.',
            'note.max'                 => 'Note must not exceed 500 characters.',
        ];
    }
}
