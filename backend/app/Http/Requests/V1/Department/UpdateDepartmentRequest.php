<?php

namespace App\Http\Requests\V1\Department;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the update-department request payload.
 *
 * Requirements: 4.3, 4.5
 */
class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by the `role.check:departments.view` middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'required', 'string', 'max:255'],
            'code'      => ['sometimes', 'required', 'string', 'max:20', 'alpha_num'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'Department name is required.',
            'code.required'    => 'Department code is required.',
            'code.alpha_num'   => 'Department code must contain only letters and numbers.',
            'code.max'         => 'Department code must not exceed 20 characters.',
            'parent_id.uuid'   => 'Parent department ID must be a valid UUID.',
        ];
    }
}
