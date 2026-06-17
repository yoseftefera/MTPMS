<?php

namespace App\Http\Requests\V1\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-purchase-order payload.
 *
 * Requirements: 10.1, 10.2
 */
class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Procurement_Officer and above).
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id'                   => ['required', 'string', 'uuid', 'exists:suppliers,id'],
            'department_id'                 => ['required', 'string', 'uuid', 'exists:departments,id'],
            'delivery_address'              => ['required', 'string', 'max:1000'],
            'required_delivery_date'        => ['required', 'date', 'after:today'],
            'purchase_request_id'           => ['nullable', 'string', 'uuid', 'exists:purchase_requests,id'],
            'bid_id'                        => ['nullable', 'string', 'uuid', 'exists:bids,id'],
            'currency'                      => ['nullable', 'string', 'size:3', 'alpha'],
            'items'                         => ['required', 'array', 'min:1'],
            'items.*.description'           => ['required', 'string', 'max:500'],
            'items.*.quantity'              => ['required', 'numeric', 'gt:0'],
            'items.*.unit_of_measure'       => ['required', 'string', 'max:50'],
            'items.*.unit_price'            => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required'              => 'The supplier ID is required.',
            'supplier_id.uuid'                  => 'The supplier ID must be a valid UUID.',
            'supplier_id.exists'                => 'The selected supplier does not exist.',
            'department_id.required'            => 'The department ID is required.',
            'department_id.uuid'                => 'The department ID must be a valid UUID.',
            'department_id.exists'              => 'The selected department does not exist.',
            'delivery_address.required'         => 'The delivery address is required.',
            'delivery_address.max'              => 'The delivery address may not exceed 1000 characters.',
            'required_delivery_date.required'   => 'The required delivery date is required.',
            'required_delivery_date.date'       => 'The required delivery date must be a valid date.',
            'required_delivery_date.after'      => 'The required delivery date must be after today.',
            'currency.size'                     => 'Currency must be a 3-letter ISO 4217 code.',
            'currency.alpha'                    => 'Currency must contain only letters.',
            'items.required'                    => 'At least one line item is required.',
            'items.array'                       => 'Items must be an array.',
            'items.min'                         => 'At least one line item is required.',
            'items.*.description.required'      => 'Each item must have a description.',
            'items.*.description.max'           => 'Item description may not exceed 500 characters.',
            'items.*.quantity.required'         => 'Each item must have a quantity.',
            'items.*.quantity.numeric'          => 'Item quantity must be a number.',
            'items.*.quantity.gt'               => 'Item quantity must be greater than zero.',
            'items.*.unit_of_measure.required'  => 'Each item must have a unit of measure.',
            'items.*.unit_of_measure.max'       => 'Unit of measure may not exceed 50 characters.',
            'items.*.unit_price.required'       => 'Each item must have a unit price.',
            'items.*.unit_price.numeric'        => 'Item unit price must be a number.',
            'items.*.unit_price.gt'             => 'Item unit price must be greater than zero.',
        ];
    }
}
