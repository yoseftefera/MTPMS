<?php

namespace App\Http\Requests\V1\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the approve-document request payload.
 *
 * Requirements: 6.3, 6.6
 */
class ApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is checked in the controller via canAct().
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.max' => 'Comment may not exceed 1000 characters.',
        ];
    }
}
