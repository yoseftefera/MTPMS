<?php

namespace App\Http\Requests\V1\Tender;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for extending a tender's submission deadline.
 *
 * The new deadline must be in the future (the service layer additionally
 * enforces it must be strictly after the current deadline).
 *
 * Requirements: 8.8
 */
class ExtendTenderDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permission enforced at route level via role.check:tenders.create
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submission_deadline' => ['required', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'submission_deadline.required' => 'The new submission deadline is required.',
            'submission_deadline.after'    => 'The new submission deadline must be a future date and time.',
        ];
    }
}
