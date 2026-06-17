<?php

namespace App\Http\Requests\V1\BidEvaluation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for submitting a score on a (bid, criteria) pair.
 *
 * Expected body:
 *   { "criteria_id": uuid, "score": integer (0-100) }
 *
 * Requirements: 9.2
 */
class SubmitScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level role check handles authorization.
    }

    public function rules(): array
    {
        return [
            'criteria_id' => ['required', 'uuid'],
            'score'       => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'criteria_id.required' => 'The criteria ID is required.',
            'criteria_id.uuid'     => 'The criteria ID must be a valid UUID.',
            'score.required'       => 'A score is required.',
            'score.integer'        => 'The score must be a whole number.',
            'score.min'            => 'The score must be at least 0.',
            'score.max'            => 'The score may not exceed 100.',
        ];
    }
}
