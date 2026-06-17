<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ApprovalWorkflowLevelResource — transforms an ApprovalWorkflowLevel model
 * into the standard API response shape.
 *
 * Requirements: 6.2
 */
class ApprovalWorkflowLevelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'workflow_id'       => $this->workflow_id,
            'level_order'       => (int) $this->level_order,
            'approver_type'     => $this->approver_type,
            'approver_role'     => $this->approver_role,
            'approver_user_id'  => $this->approver_user_id,
            'is_parallel'       => (bool) $this->is_parallel,
            'escalation_hours'  => $this->escalation_hours ? (int) $this->escalation_hours : 48,
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
