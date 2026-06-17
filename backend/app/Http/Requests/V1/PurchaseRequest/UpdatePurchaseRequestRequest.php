<?php

namespace App\Http\Requests\V1\PurchaseRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the update-purchase-request payload (partial update — all fields optional).
 *
 * Requirements: 5.2, 5.5
 */
class UpdatePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                         => ['sometimes', 'string', 'max:255'],
            'department_id'                 => ['sometimes', 'string', 'uuid', 'exists:departments,id'],
            'description'                   => ['sometimes', 'nullable', 'string'],
            'required_date'                 => ['sometimes', 'nullable', 'date', 'after:today'],
            'currency'                      => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'items'                         => ['sometimes', 'array', 'min:1'],
            'items.*.description'           => ['required_with:items', 'string', 'max:500'],
            'items.*.quantity'              => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_of_measure'       => ['required_with:items', 'string', 'max:50'],
            'items.*.estimated_unit_price'  => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.budget_code'           => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max'                             => 'The title may not exceed 255 characters.',
            'department_id.uuid'                    => 'The department ID must be a valid UUID.',
            'department_id.exists'                  => 'The selected department does not exist.',
            'required_date.date'                    => 'The required date must be a valid date.',
            'required_date.after'                   => 'The required date must be after today.',
            'currency.size'                         => 'Currency must be a 3-letter ISO 4217 code.',
            'currency.alpha'                        => 'Currency must contain only letters.',
            'items.min'                             => 'At least one line item is required.',
            'items.*.description.required_with'     => 'Each item must have a description.',
            'items.*.description.max'               => 'Item description may not exceed 500 characters.',
            'items.*.quantity.required_with'        => 'Each item must have a quantity.',
            'items.*.quantity.numeric'              => 'Item quantity must be a number.',
            'items.*.quantity.gt'                   => 'Item quantity must be greater than zero.',
            'items.*.unit_of_measure.required_with' => 'Each item must have a unit of measure.',
            'items.*.unit_of_measure.max'           => 'Unit of measure may not exceed 50 characters.',
            'items.*.estimated_unit_price.required_with' => 'Each item must have an estimated unit price.',
            'items.*.estimated_unit_price.numeric'  => 'Item estimated unit price must be a number.',
            'items.*.estimated_unit_price.gt'       => 'Item estimated unit price must be greater than zero.',
        ];
    }
}
