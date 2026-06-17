<?php

namespace App\Http\Requests\V1\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the update-user request payload.
 *
 * Requirements: 4.7
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by the `role.check:users.update` middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['sometimes', 'required', 'string', 'max:255'],
            'phone'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'avatar'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'department_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'status'        => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive', 'locked'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'   => 'Full name is required.',
            'status.in'       => 'Status must be one of: active, inactive, locked.',
        ];
    }
}
