<?php

namespace App\Repositories\Contracts;

use App\Models\Approval;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowLevel;
use Illuminate\Database\Eloquent\Collection;

/**
 * ApprovalWorkflowRepositoryInterface — data access contract for the
 * approval workflow engine.
 *
 * All implementations are automatically tenant-scoped via the HasTenantScope
 * global scope applied to ApprovalWorkflow, ApprovalWorkflowLevel, and Approval.
 *
 * Requirements: 6.1, 6.3, 6.4, 6.5, 6.6, 6.7, 6.10
 */
interface ApprovalWorkflowRepositoryInterface
{
    // -------------------------------------------------------------------------
    // Workflow lookup
    // -------------------------------------------------------------------------

    /**
     * Find an active workflow that matches the given document type, optionally
     * narrowing to a specific department.
     *
     * When a department-specific workflow exists it takes precedence over a
     * generic (department_id = null) workflow.  Returns null when no matching
     * active workflow is configured.
     *
     * @param  string       $documentType  One of: purchase_request, tender,
     *                                     purchase_order, contract, invoice
     * @param  string|null  $departmentId  UUID of the requesting department
     */
    public function findActiveWorkflow(string $documentType, ?string $departmentId = null): ?ApprovalWorkflow;

    /**
     * Find a workflow by its UUID.
     * Returns null when outside the active tenant scope.
     */
    public function findWorkflowById(string $id): ?ApprovalWorkflow;

    // -------------------------------------------------------------------------
    // Level lookup
    // -------------------------------------------------------------------------

    /**
     * Retrieve all levels for a workflow ordered by level_order ASC.
     *
     * @return Collection<int, ApprovalWorkflowLevel>
     */
    public function getLevels(string $workflowId): Collection;

    /**
     * Find a specific level by its UUID within the current tenant scope.
     */
    public function findLevelById(string $id): ?ApprovalWorkflowLevel;

    /**
     * Retrieve the first (lowest level_order) level in a workflow.
     */
    public function getFirstLevel(string $workflowId): ?ApprovalWorkflowLevel;

    /**
     * Retrieve the next level after the given level_order for a workflow.
     * Returns null when the given level_order is already the last level.
     */
    public function getNextLevel(string $workflowId, int $currentOrder): ?ApprovalWorkflowLevel;

    // -------------------------------------------------------------------------
    // Approval record operations
    // -------------------------------------------------------------------------

    /**
     * Find the pending Approval record for a specific approver, document, and
     * workflow level.  Returns null when not found or already acted upon.
     *
     * @param  string  $approverId
     * @param  string  $documentType
     * @param  string  $documentId
     * @param  string  $levelId
     */
    public function findPendingApproval(
        string $approverId,
        string $documentType,
        string $documentId,
        string $levelId,
    ): ?Approval;

    /**
     * Retrieve all Approval records for a document at a specific level,
     * eager-loading the approver relationship.
     *
     * @return Collection<int, Approval>
     */
    public function getApprovalsForLevel(
        string $documentType,
        string $documentId,
        string $levelId,
    ): Collection;

    /**
     * Count how many approvers at the given level have approved the document
     * (action = 'approved').
     */
    public function countApprovedAtLevel(
        string $documentType,
        string $documentId,
        string $levelId,
    ): int;

    /**
     * Count the total number of pending Approval records seeded for a level
     * (action = 'pending').
     */
    public function countPendingAtLevel(
        string $documentType,
        string $documentId,
        string $levelId,
    ): int;

    /**
     * Create a new Approval record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createApproval(array $attributes): Approval;

    /**
     * Update an existing Approval record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateApproval(Approval $approval, array $attributes): Approval;

    /**
     * Retrieve all pending Approval records that were created before the given
     * UTC timestamp (used by the escalation scheduler to identify overdue items).
     * Eager-loads level and workflow.
     *
     * @return Collection<int, Approval>
     */
    public function getOverdueApprovals(\DateTimeInterface $before): Collection;

    /**
     * Retrieve all Approval records for a document across all levels.
     *
     * @return Collection<int, Approval>
     */
    public function getApprovalHistory(string $documentType, string $documentId): Collection;

    // -------------------------------------------------------------------------
    // Seed pending slots
    // -------------------------------------------------------------------------

    /**
     * Create one pending Approval record per approver resolved for the level.
     * For role-based levels this inserts one record per User that holds the
     * given role within the current tenant.
     *
     * Returns the list of approver UUIDs that were seeded.
     *
     * @return list<string>  Approver UUIDs
     */
    public function seedPendingApprovals(
        ApprovalWorkflow $workflow,
        ApprovalWorkflowLevel $level,
        string $documentType,
        string $documentId,
    ): array;
}
