<?php

namespace App\Http\Requests\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/v1/payments/{payment}/record
 *
 * Requirements: 14.6, 14.8
 */
class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'            => ['required', 'numeric', 'gt:0'],
            'payment_method'    => ['required', 'string', 'max:100'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Payment amount is required.',
            'amount.gt'       => 'Payment amount must be greater than zero.',
            'payment_method.required' => 'Payment method is required.',
        ];
    }
}
