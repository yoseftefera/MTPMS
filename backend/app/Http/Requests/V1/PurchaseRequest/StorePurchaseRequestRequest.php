<?php

namespace App\Http\Requests\V1\PurchaseRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-purchase-request payload.
 *
 * Requirements: 5.1, 5.2
 */
class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Department_Staff and above).
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                         => ['required', 'string', 'max:255'],
            'department_id'                 => ['required', 'string', 'uuid', 'exists:departments,id'],
            'description'                   => ['nullable', 'string'],
            'required_date'                 => ['nullable', 'date', 'after:today'],
            'currency'                      => ['nullable', 'string', 'size:3', 'alpha'],
            'items'                         => ['required', 'array', 'min:1'],
            'items.*.description'           => ['required', 'string', 'max:500'],
            'items.*.quantity'              => ['required', 'numeric', 'gt:0'],
            'items.*.unit_of_measure'       => ['required', 'string', 'max:50'],
            'items.*.estimated_unit_price'  => ['required', 'numeric', 'gt:0'],
            'items.*.budget_code'           => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'                        => 'The title field is required.',
            'title.max'                             => 'The title may not exceed 255 characters.',
            'department_id.required'                => 'The department ID is required.',
            'department_id.uuid'                    => 'The department ID must be a valid UUID.',
            'department_id.exists'                  => 'The selected department does not exist.',
            'required_date.date'                    => 'The required date must be a valid date.',
            'required_date.after'                   => 'The required date must be after today.',
            'currency.size'                         => 'Currency must be a 3-letter ISO 4217 code.',
            'currency.alpha'                        => 'Currency must contain only letters.',
            'items.required'                        => 'At least one line item is required.',
            'items.array'                           => 'Items must be an array.',
            'items.min'                             => 'At least one line item is required.',
            'items.*.description.required'          => 'Each item must have a description.',
            'items.*.description.max'               => 'Item description may not exceed 500 characters.',
            'items.*.quantity.required'             => 'Each item must have a quantity.',
            'items.*.quantity.numeric'              => 'Item quantity must be a number.',
            'items.*.quantity.gt'                   => 'Item quantity must be greater than zero.',
            'items.*.unit_of_measure.required'      => 'Each item must have a unit of measure.',
            'items.*.unit_of_measure.max'           => 'Unit of measure may not exceed 50 characters.',
            'items.*.estimated_unit_price.required' => 'Each item must have an estimated unit price.',
            'items.*.estimated_unit_price.numeric'  => 'Item estimated unit price must be a number.',
            'items.*.estimated_unit_price.gt'       => 'Item estimated unit price must be greater than zero.',
        ];
    }
}
