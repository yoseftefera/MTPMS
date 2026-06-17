<?php

namespace App\Http\Requests\V1\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the reject-purchase-order payload.
 *
 * A rejection reason is always required.
 *
 * Requirements: 10.5
 */
class RejectPurchaseOrderRequest extends FormRequest
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
            'reason.required' => 'A rejection reason is required.',
            'reason.min'      => 'The rejection reason must be at least 5 characters.',
            'reason.max'      => 'The rejection reason may not exceed 1000 characters.',
        ];
    }
}
