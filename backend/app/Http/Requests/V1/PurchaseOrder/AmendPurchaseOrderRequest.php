<?php

namespace App\Http\Requests\V1\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the amend-purchase-order payload.
 *
 * At least one amendable field must be present.
 *
 * Requirements: 10.9
 */
class AmendPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Procurement_Officer and above).
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_address'              => ['sometimes', 'required', 'string', 'max:1000'],
            'required_delivery_date'        => ['sometimes', 'required', 'date'],
            'notes'                         => ['nullable', 'string'],
            'items'                         => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.description'           => ['required_with:items', 'string', 'max:500'],
            'items.*.quantity'              => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_of_measure'       => ['required_with:items', 'string', 'max:50'],
            'items.*.unit_price'            => ['required_with:items', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_address.required'         => 'The delivery address is required.',
            'delivery_address.max'              => 'The delivery address may not exceed 1000 characters.',
            'required_delivery_date.required'   => 'The required delivery date is required.',
            'required_delivery_date.date'       => 'The required delivery date must be a valid date.',
            'items.required'                    => 'At least one line item is required.',
            'items.min'                         => 'At least one line item is required.',
            'items.*.description.required_with' => 'Each item must have a description.',
            'items.*.quantity.required_with'    => 'Each item must have a quantity.',
            'items.*.quantity.gt'               => 'Item quantity must be greater than zero.',
            'items.*.unit_of_measure.required_with' => 'Each item must have a unit of measure.',
            'items.*.unit_price.required_with'  => 'Each item must have a unit price.',
            'items.*.unit_price.gt'             => 'Item unit price must be greater than zero.',
        ];
    }
}
