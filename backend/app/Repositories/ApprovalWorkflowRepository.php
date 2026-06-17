<?php

namespace App\Repositories;

use App\Models\Approval;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowLevel;
use App\Models\User;
use App\Repositories\Contracts\ApprovalWorkflowRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * ApprovalWorkflowRepository — Eloquent implementation of
 * ApprovalWorkflowRepositoryInterface.
 *
 * All queries are automatically tenant-scoped by the HasTenantScope global
 * scope applied to ApprovalWorkflow, ApprovalWorkflowLevel, and Approval.
 *
 * Requirements: 6.1, 6.3, 6.4, 6.5, 6.6, 6.7, 6.10
 */
class ApprovalWorkflowRepository implements ApprovalWorkflowRepositoryInterface
{
    // -------------------------------------------------------------------------
    // Workflow lookup
    // -------------------------------------------------------------------------

    /**
     * Find an active workflow matching the document type, preferring a
     * department-specific workflow over a generic one.
     *
     * Priority:
     *  1. Active workflow WHERE document_type = $type AND department_id = $departmentId
     *  2. Active workflow WHERE document_type = $type AND department_id IS NULL
     *
     * Requirements: 6.1
     */
    public function findActiveWorkflow(string $documentType, ?string $departmentId = null): ?ApprovalWorkflow
    {
        $query = ApprovalWorkflow::with('levels')
            ->where('document_type', $documentType)
            ->where('is_active', true);

        if ($departmentId) {
            // Try department-specific first
            $specific = (clone $query)
                ->where('department_id', $departmentId)
                ->first();

            if ($specific) {
                return $specific;
            }
        }

        // Fall back to generic (no department restriction)
        return $query->whereNull('department_id')->first();
    }

    /**
     * Find a workflow by its UUID within the current tenant scope.
     * Eager-loads levels for immediate use.
     */
    public function findWorkflowById(string $id): ?ApprovalWorkflow
    {
        return ApprovalWorkflow::with('levels')->find($id);
    }

    // -------------------------------------------------------------------------
    // Level lookup
    // -------------------------------------------------------------------------

    /**
     * Retrieve all levels for a workflow ordered by level_order ASC.
     *
     * @return Collection<int, ApprovalWorkflowLevel>
     */
    public function getLevels(string $workflowId): Collection
    {
        return ApprovalWorkflowLevel::where('workflow_id', $workflowId)
            ->orderBy('level_order')
            ->get();
    }

    /**
     * Find a workflow level by its UUID within the current tenant scope.
     */
    public function findLevelById(string $id): ?ApprovalWorkflowLevel
    {
        return ApprovalWorkflowLevel::find($id);
    }

    /**
     * Retrieve the first (lowest level_order) level in a workflow.
     */
    public function getFirstLevel(string $workflowId): ?ApprovalWorkflowLevel
    {
        return ApprovalWorkflowLevel::where('workflow_id', $workflowId)
            ->orderBy('level_order')
            ->first();
    }

    /**
     * Retrieve the next level after the given level_order for a workflow.
     * Returns null when there is no subsequent level (document is fully approved).
     */
    public function getNextLevel(string $workflowId, int $currentOrder): ?ApprovalWorkflowLevel
    {
        return ApprovalWorkflowLevel::where('workflow_id', $workflowId)
            ->where('level_order', '>', $currentOrder)
            ->orderBy('level_order')
            ->first();
    }

    // -------------------------------------------------------------------------
    // Approval record operations
    // -------------------------------------------------------------------------

    /**
     * Find the pending Approval record for a specific approver, document, and level.
     * Returns null when not found or already acted upon.
     */
    public function findPendingApproval(
        string $approverId,
        string $documentType,
        string $documentId,
        string $levelId,
    ): ?Approval {
        return Approval::where('approver_id', $approverId)
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('level_id', $levelId)
            ->where('action', 'pending')
            ->first();
    }

    /**
     * Retrieve all Approval records for a document at a specific level.
     *
     * @return Collection<int, Approval>
     */
    public function getApprovalsForLevel(
        string $documentType,
        string $documentId,
        string $levelId,
    ): Collection {
        return Approval::with('approver')
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('level_id', $levelId)
            ->get();
    }

    /**
     * Count how many approvers at the given level have set action = 'approved'.
     */
    public function countApprovedAtLevel(
        string $documentType,
        string $documentId,
        string $levelId,
    ): int {
        return Approval::where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('level_id', $levelId)
            ->where('action', 'approved')
            ->count();
    }

    /**
     * Count pending Approval records at a level (action = 'pending').
     */
    public function countPendingAtLevel(
        string $documentType,
        string $documentId,
        string $levelId,
    ): int {
        return Approval::where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('level_id', $levelId)
            ->where('action', 'pending')
            ->count();
    }

    /**
     * Create a new Approval record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createApproval(array $attributes): Approval
    {
        return Approval::create($attributes);
    }

    /**
     * Update an existing Approval record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateApproval(Approval $approval, array $attributes): Approval
    {
        $approval->update($attributes);

        return $approval->fresh();
    }

    /**
     * Retrieve all pending Approval records older than $before for the
     * escalation scheduler.  Eager-loads level and workflow.
     *
     * @return Collection<int, Approval>
     */
    public function getOverdueApprovals(\DateTimeInterface $before): Collection
    {
        return Approval::withoutGlobalScopes()
            ->with(['level', 'workflow'])
            ->where('action', 'pending')
            ->where('created_at', '<', $before)
            ->get();
    }

    /**
     * Retrieve all Approval records for a document across all levels.
     * Eager-loads approver and level relationships.
     *
     * @return Collection<int, Approval>
     */
    public function getApprovalHistory(string $documentType, string $documentId): Collection
    {
        return Approval::with(['approver', 'level'])
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->orderBy('created_at')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Seed pending slots
    // -------------------------------------------------------------------------

    /**
     * Create pending Approval records for every approver resolved from the level
     * configuration and return the list of approver UUIDs.
     *
     * Approver resolution rules:
     *  - approver_type = 'user'  → single record for approver_user_id
     *  - approver_type = 'role'  → one record per User with that role in this tenant
     *
     * Requirements: 6.10
     *
     * @return list<string>  Approver UUIDs that received a pending record
     */
    public function seedPendingApprovals(
        ApprovalWorkflow $workflow,
        ApprovalWorkflowLevel $level,
        string $documentType,
        string $documentId,
    ): array {
        $approverIds = $this->resolveApprovers($workflow->tenant_id, $level);

        foreach ($approverIds as $approverId) {
            // Avoid duplicate records if called more than once (idempotent)
            $exists = Approval::withoutGlobalScopes()
                ->where('workflow_id', $workflow->id)
                ->where('level_id', $level->id)
                ->where('document_type', $documentType)
                ->where('document_id', $documentId)
                ->where('approver_id', $approverId)
                ->exists();

            if (! $exists) {
                Approval::create([
                    'tenant_id'     => $workflow->tenant_id,
                    'workflow_id'   => $workflow->id,
                    'level_id'      => $level->id,
                    'document_type' => $documentType,
                    'document_id'   => $documentId,
                    'approver_id'   => $approverId,
                    'action'        => 'pending',
                    'comment'       => null,
                    'acted_at'      => null,
                ]);
            }
        }

        return $approverIds;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the list of approver UUIDs for a workflow level.
     *
     * @return list<string>
     */
    private function resolveApprovers(string $tenantId, ApprovalWorkflowLevel $level): array
    {
        if ($level->approver_type === 'user') {
            return $level->approver_user_id ? [$level->approver_user_id] : [];
        }

        // approver_type = 'role' — find all active users with this role in the tenant
        if (! $level->approver_role) {
            return [];
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', $level->approver_role))
            ->pluck('id')
            ->toArray();
    }
}
