<?php

namespace App\Http\Requests\V1\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the reject-document request payload.
 *
 * Requirements: 6.4, 6.6
 */
class RejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is checked in the controller via canAct().
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required.',
            'reason.min'      => 'The rejection reason must be at least 10 characters.',
            'reason.max'      => 'The rejection reason may not exceed 2000 characters.',
        ];
    }
}
