<?php

namespace App\Http\Requests\V1\Bid;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for revising an existing bid.
 *
 * All fields are optional — only send what needs to change.
 *
 * Requirements: 8.4, 8.5
 */
class UpdateBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level role check handles authorization.
    }

    public function rules(): array
    {
        return [
            'total_amount'    => ['sometimes', 'numeric', 'min:0.01'],
            'currency'        => ['sometimes', 'nullable', 'string', 'size:3'],
            'delivery_days'   => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'technical_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'total_amount.numeric'   => 'The total bid amount must be a number.',
            'total_amount.min'       => 'The total bid amount must be greater than zero.',
            'currency.size'          => 'The currency must be a 3-letter ISO code (e.g. USD).',
            'delivery_days.integer'  => 'The delivery days must be a whole number.',
            'delivery_days.min'      => 'The delivery days must be at least 1.',
            'delivery_days.max'      => 'The delivery days may not exceed 3650.',
        ];
    }
}
