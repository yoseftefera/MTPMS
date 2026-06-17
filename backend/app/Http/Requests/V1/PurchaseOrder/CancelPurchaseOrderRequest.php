<?php

namespace App\Http\Requests\V1\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the cancel-purchase-order payload.
 *
 * A cancellation reason is always required.
 *
 * Requirements: 10.10
 */
class CancelPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A cancellation reason is required.',
            'reason.min'      => 'The cancellation reason must be at least 5 characters.',
            'reason.max'      => 'The cancellation reason may not exceed 1000 characters.',
        ];
    }
}
