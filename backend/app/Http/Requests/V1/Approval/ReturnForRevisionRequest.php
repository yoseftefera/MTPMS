<?php

namespace App\Http\Requests\V1\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the return-for-revision request payload.
 *
 * Requirements: 6.5, 6.6
 */
class ReturnForRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is checked in the controller via canAct().
        return true;
    }

    public function rules(): array
    {
        return [
            'comments' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'comments.required' => 'Revision comments are required.',
            'comments.min'      => 'Revision comments must be at least 10 characters.',
            'comments.max'      => 'Revision comments may not exceed 2000 characters.',
        ];
    }
}
