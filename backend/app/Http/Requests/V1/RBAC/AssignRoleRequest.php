<?php

namespace App\Http\Requests\V1\RBAC;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the role assignment request payload.
 *
 * Requirements: 3.2, 3.3, 3.5
 */
class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by the `role.check:roles.assign` middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.required' => 'A role name is required.',
            'role.string'   => 'The role name must be a string.',
            'role.max'      => 'The role name must not exceed 100 characters.',
        ];
    }
}
