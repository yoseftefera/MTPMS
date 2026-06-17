<?php

namespace App\Http\Requests\V1\ApprovalWorkflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the update-approval-workflow request payload.
 *
 * Requirements: 6.2
 */
class UpdateApprovalWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Tenant_Admin only).
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['sometimes', 'string', 'max:255'],
            'department_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max'          => 'The workflow name may not exceed 255 characters.',
            'department_id.uuid' => 'The department ID must be a valid UUID.',
        ];
    }
}
