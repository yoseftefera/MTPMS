<?php

namespace App\Http\Requests\V1\ApprovalWorkflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-approval-workflow request payload.
 *
 * Requirements: 6.2
 */
class StoreApprovalWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled by role middleware (Tenant_Admin only).
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                          => ['required', 'string', 'max:255'],
            'document_type'                 => ['required', 'string', 'in:purchase_request,tender,purchase_order,contract,invoice'],
            'department_id'                 => ['nullable', 'string', 'uuid'],
            'is_active'                     => ['boolean'],

            // Levels — at least one level must be provided when creating
            'levels'                        => ['required', 'array', 'min:1'],
            'levels.*.level_order'          => ['required', 'integer', 'min:1'],
            'levels.*.approver_type'        => ['required', 'string', 'in:user,role'],
            'levels.*.approver_role'        => ['nullable', 'required_if:levels.*.approver_type,role', 'string', 'max:100'],
            'levels.*.approver_user_id'     => ['nullable', 'required_if:levels.*.approver_type,user', 'string', 'uuid'],
            'levels.*.is_parallel'          => ['boolean'],
            'levels.*.escalation_hours'     => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                             => 'The workflow name is required.',
            'name.max'                                  => 'The workflow name may not exceed 255 characters.',
            'document_type.required'                    => 'The document type is required.',
            'document_type.in'                          => 'The document type must be one of: purchase_request, tender, purchase_order, contract, invoice.',
            'department_id.uuid'                        => 'The department ID must be a valid UUID.',
            'levels.required'                           => 'At least one approval level is required.',
            'levels.min'                                => 'At least one approval level is required.',
            'levels.*.level_order.required'             => 'Each level must have a level order.',
            'levels.*.level_order.integer'              => 'Level order must be an integer.',
            'levels.*.level_order.min'                  => 'Level order must be at least 1.',
            'levels.*.approver_type.required'           => 'Each level must specify an approver type.',
            'levels.*.approver_type.in'                 => 'Approver type must be either "user" or "role".',
            'levels.*.approver_role.required_if'        => 'An approver role is required when approver type is "role".',
            'levels.*.approver_user_id.required_if'     => 'An approver user ID is required when approver type is "user".',
            'levels.*.approver_user_id.uuid'            => 'Approver user ID must be a valid UUID.',
            'levels.*.escalation_hours.min'             => 'Escalation hours must be at least 1.',
            'levels.*.escalation_hours.max'             => 'Escalation hours may not exceed 720 (30 days).',
        ];
    }
}
