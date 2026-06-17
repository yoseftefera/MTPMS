<?php

namespace App\Http\Requests\V1\BidEvaluation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for selecting the winning bid on a tender.
 *
 * Expected body:
 *   { "bid_id": uuid, "justification": string }
 *
 * Requirements: 9.5
 */
class SelectWinnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level role check handles authorization.
    }

    public function rules(): array
    {
        return [
            'bid_id'        => ['required', 'uuid'],
            'justification' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'bid_id.required'        => 'The bid ID is required.',
            'bid_id.uuid'            => 'The bid ID must be a valid UUID.',
            'justification.required' => 'A justification is required when selecting the winning bid.',
            'justification.string'   => 'The justification must be a string.',
            'justification.max'      => 'The justification may not exceed 5000 characters.',
        ];
    }
}
