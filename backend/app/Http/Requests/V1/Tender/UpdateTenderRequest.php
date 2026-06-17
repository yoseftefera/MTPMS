<?php

namespace App\Http\Requests\V1\Tender;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for updating a tender (draft status only).
 *
 * Requirements: 8.1
 */
class UpdateTenderRequest extends FormRequest
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
            'title'               => ['sometimes', 'string', 'max:255'],
            'description'         => ['sometimes', 'string'],
            'category'            => ['sometimes', 'string', 'max:100'],
            'tender_type'         => ['sometimes', 'in:open,restricted,single_source'],
            'estimated_value'     => ['sometimes', 'numeric', 'min:0.01'],
            'submission_deadline' => ['sometimes', 'date', 'after:now'],
            'supplier_id'         => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'submission_deadline.after' => 'The submission deadline must be a future date and time.',
            'tender_type.in'            => 'Tender type must be one of: open, restricted, or single_source.',
            'estimated_value.min'       => 'The estimated value must be greater than zero.',
        ];
    }
}
