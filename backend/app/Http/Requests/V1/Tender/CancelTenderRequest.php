<?php

namespace App\Http\Requests\V1\Tender;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for cancelling a tender.
 *
 * A mandatory cancellation reason must be provided (Req 8.9).
 *
 * Requirements: 8.9
 */
class CancelTenderRequest extends FormRequest
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
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'A cancellation reason is required.',
            'cancellation_reason.min'      => 'The cancellation reason must be at least 10 characters.',
        ];
    }
}
