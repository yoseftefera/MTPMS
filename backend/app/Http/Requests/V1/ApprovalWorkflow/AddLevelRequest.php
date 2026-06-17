<?php

namespace App\Http\Requests\V1\ApprovalWorkflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the add-level request payload.
 *
 * Requirements: 6.2
 */
class AddLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Tenant_Admin only).
        return true;
    }

    public function rules(): array
    {
        return [
            'level_order'      => ['required', 'integer', 'min:1'],
            'approver_type'    => ['required', 'string', 'in:user,role'],
            'approver_role'    => ['nullable', 'required_if:approver_type,role', 'string', 'max:100'],
            'approver_user_id' => ['nullable', 'required_if:approver_type,user', 'string', 'uuid'],
            'is_parallel'      => ['boolean'],
            'escalation_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }

    public function messages(): array
    {
        return [
            'level_order.required'          => 'Level order is required.',
            'level_order.integer'           => 'Level order must be an integer.',
            'level_order.min'               => 'Level order must be at least 1.',
            'approver_type.required'        => 'Approver type is required.',
            'approver_type.in'              => 'Approver type must be either "user" or "role".',
            'approver_role.required_if'     => 'An approver role is required when approver type is "role".',
            'approver_user_id.required_if'  => 'An approver user ID is required when approver type is "user".',
            'approver_user_id.uuid'         => 'Approver user ID must be a valid UUID.',
            'escalation_hours.min'          => 'Escalation hours must be at least 1.',
            'escalation_hours.max'          => 'Escalation hours may not exceed 720 (30 days).',
        ];
    }
}
