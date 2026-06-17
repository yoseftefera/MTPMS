<?php

namespace App\Http\Requests\V1\BidEvaluation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for configuring evaluation criteria on a tender.
 *
 * Expected body:
 *   {
 *     "criteria": [
 *       { "name": string, "weight": number (0-100), "description"?: string }
 *     ]
 *   }
 *
 * Business rule: weights must sum to 100 (validated in BidEvaluationService).
 *
 * Requirements: 9.1
 */
class ConfigureCriteriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level role check handles authorization.
    }

    public function rules(): array
    {
        return [
            'criteria'                  => ['required', 'array', 'min:1'],
            'criteria.*.name'           => ['required', 'string', 'max:255'],
            'criteria.*.weight'         => ['required', 'numeric', 'min:0', 'max:100'],
            'criteria.*.description'    => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'criteria.required'             => 'At least one evaluation criterion is required.',
            'criteria.array'                => 'The criteria field must be an array.',
            'criteria.min'                  => 'At least one evaluation criterion is required.',
            'criteria.*.name.required'      => 'Each criterion must have a name.',
            'criteria.*.name.max'           => 'Each criterion name may not exceed 255 characters.',
            'criteria.*.weight.required'    => 'Each criterion must have a weight.',
            'criteria.*.weight.numeric'     => 'Each criterion weight must be a number.',
            'criteria.*.weight.min'         => 'Each criterion weight must be at least 0.',
            'criteria.*.weight.max'         => 'Each criterion weight may not exceed 100.',
            'criteria.*.description.max'    => 'Each criterion description may not exceed 1000 characters.',
        ];
    }
}
