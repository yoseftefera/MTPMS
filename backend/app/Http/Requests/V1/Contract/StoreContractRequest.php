<?php

namespace App\Http\Requests\V1\Contract;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-contract payload.
 *
 * Requirements: 11.1, 11.2
 */
class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id'       => ['required', 'string', 'uuid', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'string', 'uuid', 'exists:purchase_orders,id'],
            'tender_id'         => ['nullable', 'string', 'uuid', 'exists:tenders,id'],
            'title'             => ['required', 'string', 'max:500'],
            'scope'             => ['required', 'string', 'max:5000'],
            'total_value'       => ['required', 'numeric', 'gt:0'],
            'currency'          => ['nullable', 'string', 'size:3', 'alpha'],
            'start_date'        => ['required', 'date'],
            'end_date'          => ['required', 'date', 'after:start_date'],
            'payment_terms'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required'    => 'The supplier ID is required.',
            'supplier_id.uuid'        => 'The supplier ID must be a valid UUID.',
            'supplier_id.exists'      => 'The selected supplier does not exist.',
            'title.required'          => 'The contract title is required.',
            'title.max'               => 'The contract title may not exceed 500 characters.',
            'scope.required'          => 'The contract scope is required.',
            'scope.max'               => 'The contract scope may not exceed 5000 characters.',
            'total_value.required'    => 'The total contract value is required.',
            'total_value.numeric'     => 'The total contract value must be a number.',
            'total_value.gt'          => 'The total contract value must be greater than zero.',
            'currency.size'           => 'Currency must be a 3-letter ISO 4217 code.',
            'currency.alpha'          => 'Currency must contain only letters.',
            'start_date.required'     => 'The start date is required.',
            'start_date.date'         => 'The start date must be a valid date.',
            'end_date.required'       => 'The end date is required.',
            'end_date.date'           => 'The end date must be a valid date.',
            'end_date.after'          => 'The end date must be after the start date.',
        ];
    }
}
