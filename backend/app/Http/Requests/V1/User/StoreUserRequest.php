<?php

namespace App\Http\Requests\V1\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-user request payload.
 *
 * Requirements: 4.1, 4.8
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by the `role.check:users.create` middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'string', 'email', 'max:255'],
            'department_id' => ['nullable', 'string', 'uuid'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'role'          => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'  => 'Full name is required.',
            'email.required' => 'Email address is required.',
            'email.email'    => 'Please provide a valid email address.',
        ];
    }
}
