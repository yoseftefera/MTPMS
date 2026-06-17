<?php

namespace App\Services;

use App\Events\DocumentApproved;
use App\Events\DocumentRejected;
use App\Events\DocumentReturnedForRevision;
use App\Exceptions\UnauthorizedTenantAccessException;
use App\Jobs\SendApprovalOutcomeNotificationJob;
use App\Jobs\SendApprovalRequestNotificationJob;
use App\Jobs\SendEscalationNotificationJob;
use App\Jobs\WriteAuditLogJob;
use App\Models\Approval;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowLevel;
use App\Models\User;
use App\Repositories\Contracts\ApprovalWorkflowRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use App\Services\DocumentStatusUpdater;

/**
 * ApprovalWorkflowService — the core engine for multi-level document approval.
 *
 * Responsibilities:
 *  - Resolve and start an approval workflow for any supported document type
 *  - advance()            — record an approval and advance to next level (or mark as fully approved)
 *  - reject()             — reject the document with a mandatory reason
 *  - returnForRevision()  — return the document for revision with mandatory comments
 *  - processEscalations() — called by the hourly scheduler; escalate overdue approvals
 *
 * Parallel approval support:
 *  When a level has is_parallel = true, ALL designated approvers must individually
 *  approve before the document advances to the next level.  A single rejection or
 *  return at a parallel level immediately terminates the workflow for that action.
 *
 * Escalation:
 *  After escalation_hours (default 48) the pending approver's supervisor is notified.
 *  When no supervisor is configured the Tenant_Admin is used as fallback.
 *
 * All approval actions are written to the Audit_Log via WriteAuditLogJob.
 * Originator and level approvers are notified via queued notification jobs.
 *
 * Requirements: 6.1, 6.3, 6.4, 6.5, 6.6, 6.7, 6.10
 */
class ApprovalWorkflowService
{
    public function __construct(
        private readonly ApprovalWorkflowRepositoryInterface $repository,
        private readonly ?DocumentStatusUpdater $statusUpdater = null,
    ) {}

    /**
     * Resolve the status updater, defaulting to a new instance when not injected.
     */
    private function getStatusUpdater(): DocumentStatusUpdater
    {
        return $this->statusUpdater ?? new DocumentStatusUpdater();
    }

    // =========================================================================
    // 6.1 / 6.10 — initiate(): start workflow (task 7.1 primary entry point)
    // =========================================================================

    /**
     * Initiate an approval workflow for a document.
     *
     * Finds the active workflow for the document type + tenant, creates
     * pending Approval records for all level-1 approvers, and notifies them.
     * Returns the ApprovalWorkflow instance on success, or throws on failure.
     *
     * Requirements: 6.1, 6.10
     *
     * @throws \RuntimeException  when no active workflow is configured
     */
    public function initiate(
        string $documentType,
        string $documentId,
        string $tenantId,
    ): ApprovalWorkflow {
        $workflow = $this->repository->findActiveWorkflow($documentType);

        if (! $workflow) {
            throw new \RuntimeException(
                "No active approval workflow configured for document type '{$documentType}'."
            );
        }

        $firstLevel = $this->repository->getFirstLevel($workflow->id);

        if (! $firstLevel) {
            throw new \RuntimeException(
                'The configured approval workflow has no levels defined.'
            );
        }

        try {
            DB::beginTransaction();

            $approverIds = $this->repository->seedPendingApprovals(
                workflow:     $workflow,
                level:        $firstLevel,
                documentType: $documentType,
                documentId:   $documentId,
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ApprovalWorkflowService::initiate failed', [
                'document_type' => $documentType,
                'document_id'   => $documentId,
                'error'         => $e->getMessage(),
            ]);
            throw $e;
        }

        if (! empty($approverIds)) {
            SendApprovalRequestNotificationJob::dispatch(
                $tenantId,
                $documentType,
                $documentId,
                $firstLevel->level_order,
                $approverIds,
            );
        }

        WriteAuditLogJob::dispatch(
            tenantId:   $tenantId,
            userId:     null,
            userRole:   null,
            actionType: 'approval_workflow_started',
            entityType: $documentType,
            entityId:   $documentId,
            before:     null,
            after:      ['workflow_id' => $workflow->id, 'first_level' => $firstLevel->level_order],
            ipAddress:  '0.0.0.0',
            requestId:  null,
        );

        return $workflow;
    }

    // =========================================================================
    // 6.6 — canAct(): authorisation guard
    // =========================================================================

    /**
     * Determine whether the given approver is permitted to act on an Approval.
     *
     * Returns true only when ALL of:
     *  1. The approval record is still pending (action = 'pending').
     *  2. The approver's identity matches the level's approver_type configuration
     *     (user match for approver_type='user'; role match for approver_type='role').
     *
     * Does NOT throw — callers that need to enforce access should call this first
     * and throw UnauthorizedTenantAccessException when it returns false.
     *
     * Requirements: 6.6
     */
    public function canAct(Approval $approval, User $approver): bool
    {
        if ($approval->action !== 'pending') {
            return false;
        }

        $level = $approval->level;

        if (! $level) {
            return false;
        }

        if ($level->approver_type === 'user') {
            return $approval->approver_id === $approver->id;
        }

        if ($level->approver_type === 'role') {
            return $approver->hasRole($level->approver_role ?? '');
        }

        return false;
    }

    // =========================================================================
    // 6.9 — escalatePendingApprovals(): hourly scheduler entry point
    // =========================================================================

    /**
     * Scan all pending Approval records across all tenants and dispatch
     * SendEscalationNotificationJob for any overdue ones.
     *
     * This is the scheduler-facing alias for processEscalations().
     *
     * Requirements: 6.9
     *
     * @return int  Number of escalation notifications dispatched
     */
    public function escalatePendingApprovals(): int
    {
        return $this->processEscalations();
    }

    // =========================================================================
    // 6.1 / 6.10 — Start workflow for a document
    // =========================================================================

    /**
     * Initiate an approval workflow for the given document.
     *
     * Locates the matching active workflow for the document type (and optional
     * department), creates pending Approval records for every approver at the
     * first level, and dispatches approval-request notifications.
     *
     * Returns an error envelope when no active workflow is configured.
     *
     * Requirements: 6.1, 6.10
     *
     * @param  string       $documentType   One of: purchase_request, tender,
     *                                      purchase_order, contract, invoice
     * @param  string       $documentId     UUID of the document
     * @param  string|null  $departmentId   UUID of the originating department
     * @param  User|null    $actor          User who triggered the workflow start
     * @param  string       $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: array|null, errors: array|null}
     */
    public function startWorkflow(
        string $documentType,
        string $documentId,
        ?string $departmentId = null,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $workflow = $this->repository->findActiveWorkflow($documentType, $departmentId);

        if (! $workflow) {
            return [
                'success' => false,
                'message' => "No active approval workflow configured for document type '{$documentType}'.",
                'code'    => 422,
                'data'    => null,
                'errors'  => ['workflow' => ["No active approval workflow configured for document type '{$documentType}'."]],
            ];
        }

        $firstLevel = $this->repository->getFirstLevel($workflow->id);

        if (! $firstLevel) {
            return [
                'success' => false,
                'message' => 'The configured approval workflow has no levels defined.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['workflow' => ['The configured approval workflow has no levels defined.']],
            ];
        }

        try {
            DB::beginTransaction();

            $approverIds = $this->repository->seedPendingApprovals(
                workflow:     $workflow,
                level:        $firstLevel,
                documentType: $documentType,
                documentId:   $documentId,
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ApprovalWorkflowService::startWorkflow failed', [
                'document_type' => $documentType,
                'document_id'   => $documentId,
                'error'         => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to start approval workflow. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        // Notify all approvers at the first level
        if (! empty($approverIds)) {
            SendApprovalRequestNotificationJob::dispatch(
                $workflow->tenant_id,
                $documentType,
                $documentId,
                $firstLevel->level_order,
                $approverIds,
            );
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            tenantId:   $workflow->tenant_id,
            actionType: 'approval_workflow_started',
            entityType: $documentType,
            entityId:   $documentId,
            before:     null,
            after:      ['workflow_id' => $workflow->id, 'first_level' => $firstLevel->level_order],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Approval workflow started successfully.',
            'code'    => 201,
            'data'    => [
                'workflow_id'  => $workflow->id,
                'level_order'  => $firstLevel->level_order,
                'approver_ids' => $approverIds,
            ],
            'errors'  => null,
        ];
    }

    // =========================================================================
    // 6.3 — advance(): approver approves at current level
    // =========================================================================

    /**
     * Record an approval action for the current approver at the current level.
     *
     * For parallel levels (is_parallel = true) the document advances only when
     * ALL pending approvers at the level have approved.
     * For sequential levels (is_parallel = false) a single approval advances.
     *
     * When advancing:
     *  - If a next level exists: seed pending approvals and notify next-level approvers.
     *  - If no next level exists: fire DocumentApproved event and notify originator.
     *
     * Requirements: 6.3, 6.6, 6.7
     *
     * @param  string       $documentType
     * @param  string       $documentId
     * @param  User         $approver       The authenticated user performing the action
     * @param  string|null  $comment        Optional approval comment
     * @param  string       $originatorId   UUID of the user who created the document
     * @param  string|null  $departmentId   UUID of the originating department
     * @param  User|null    $actor          Usually identical to $approver (passed for audit)
     * @param  string       $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: array|null, errors: array|null}
     */
    public function advance(
        string $documentType,
        string $documentId,
        User $approver,
        ?string $comment = null,
        string $originatorId = '',
        ?string $departmentId = null,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $actor ??= $approver;

        // Locate the approver's pending slot
        [$pendingApproval, $currentLevel, $workflow, $errorResponse] =
            $this->resolvePendingSlot($documentType, $documentId, $approver, $departmentId);

        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            DB::beginTransaction();

            // Mark this approver's slot as approved
            $this->repository->updateApproval($pendingApproval, [
                'action'   => 'approved',
                'comment'  => $comment,
                'acted_at' => now(),
            ]);

            // Check if the level is fully approved
            $levelAdvances = $this->levelIsFullyApproved(
                $documentType,
                $documentId,
                $currentLevel,
            );

            $outcome = 'partial_parallel'; // Default: parallel level not yet complete

            if ($levelAdvances) {
                $nextLevel = $this->repository->getNextLevel($workflow->id, $currentLevel->level_order);

                if ($nextLevel) {
                    // Advance to next level
                    $approverIds = $this->repository->seedPendingApprovals(
                        workflow:     $workflow,
                        level:        $nextLevel,
                        documentType: $documentType,
                        documentId:   $documentId,
                    );

                    DB::commit();

                    if (! empty($approverIds)) {
                        SendApprovalRequestNotificationJob::dispatch(
                            $workflow->tenant_id,
                            $documentType,
                            $documentId,
                            $nextLevel->level_order,
                            $approverIds,
                        );
                    }

                    $outcome = 'advanced';
                } else {
                    // Final level — document is fully approved
                    $this->getStatusUpdater()->updateStatus($documentType, $documentId, 'approved');

                    DB::commit();

                    DocumentApproved::dispatch($documentType, $documentId, $workflow->tenant_id);

                    if ($originatorId) {
                        SendApprovalOutcomeNotificationJob::dispatch(
                            $workflow->tenant_id,
                            $documentType,
                            $documentId,
                            'approved',
                            $comment ?? '',
                            [$originatorId],
                        );
                    }

                    $outcome = 'approved';
                }
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ApprovalWorkflowService::advance failed', [
                'document_type' => $documentType,
                'document_id'   => $documentId,
                'approver_id'   => $approver->id,
                'error'         => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to record approval. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            tenantId:   $workflow->tenant_id,
            actionType: 'document_approved',
            entityType: $documentType,
            entityId:   $documentId,
            before:     ['action' => 'pending', 'level_order' => $currentLevel->level_order],
            after:      ['action' => 'approved', 'outcome' => $outcome, 'comment' => $comment],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        $messages = [
            'approved'         => 'Document fully approved.',
            'advanced'         => 'Approval recorded. Document advanced to the next level.',
            'partial_parallel' => 'Approval recorded. Waiting for remaining parallel approvers.',
        ];

        return [
            'success' => true,
            'message' => $messages[$outcome] ?? 'Approval recorded.',
            'code'    => 200,
            'data'    => ['outcome' => $outcome, 'level_order' => $currentLevel->level_order],
            'errors'  => null,
        ];
    }

    // =========================================================================
    // 6.4 — reject(): mandatory reason
    // =========================================================================

    /**
     * Reject the document at the current approval level.
     *
     * A non-empty reason is mandatory. The document transitions to 'rejected'
     * status (the caller is responsible for updating the document model), the
     * DocumentRejected event is fired, and the originator is notified.
     *
     * Requirements: 6.4, 6.7
     *
     * @param  string  $documentType
     * @param  string  $documentId
     * @param  User    $approver
     * @param  string  $reason        Mandatory — rejection reason
     * @param  string  $originatorId  UUID of the document creator
     * @param  string|null  $departmentId
     * @param  User|null    $actor
     * @param  string  $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: array|null, errors: array|null}
     *
     * @throws InvalidArgumentException when reason is empty
     */
    public function reject(
        string $documentType,
        string $documentId,
        User $approver,
        string $reason,
        string $originatorId = '',
        ?string $departmentId = null,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $actor ??= $approver;

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        [$pendingApproval, $currentLevel, $workflow, $errorResponse] =
            $this->resolvePendingSlot($documentType, $documentId, $approver, $departmentId);

        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            DB::beginTransaction();

            $this->repository->updateApproval($pendingApproval, [
                'action'   => 'rejected',
                'comment'  => $reason,
                'acted_at' => now(),
            ]);

            $this->getStatusUpdater()->updateStatus($documentType, $documentId, 'rejected');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ApprovalWorkflowService::reject failed', [
                'document_type' => $documentType,
                'document_id'   => $documentId,
                'approver_id'   => $approver->id,
                'error'         => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to record rejection. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        // Fire event — listeners update document status to 'rejected'
        DocumentRejected::dispatch($documentType, $documentId, $workflow->tenant_id, $reason, $approver->id);

        // Notify originator
        if ($originatorId) {
            SendApprovalOutcomeNotificationJob::dispatch(
                $workflow->tenant_id,
                $documentType,
                $documentId,
                'rejected',
                $reason,
                [$originatorId],
            );
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            tenantId:   $workflow->tenant_id,
            actionType: 'document_rejected',
            entityType: $documentType,
            entityId:   $documentId,
            before:     ['action' => 'pending', 'level_order' => $currentLevel->level_order],
            after:      ['action' => 'rejected', 'reason' => $reason],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Document rejected successfully.',
            'code'    => 200,
            'data'    => ['outcome' => 'rejected', 'level_order' => $currentLevel->level_order],
            'errors'  => null,
        ];
    }

    // =========================================================================
    // 6.5 — returnForRevision(): mandatory comments
    // =========================================================================

    /**
     * Return the document to its originator for revision.
     *
     * Non-empty comments are mandatory. The DocumentReturnedForRevision event is
     * fired, and the originator is notified with the comments.  The document
     * transitions to 'revision_required' (caller updates the document model).
     *
     * Requirements: 6.5, 6.7
     *
     * @param  string  $documentType
     * @param  string  $documentId
     * @param  User    $approver
     * @param  string  $comments      Mandatory revision comments
     * @param  string  $originatorId  UUID of the document creator
     * @param  string|null  $departmentId
     * @param  User|null    $actor
     * @param  string  $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: array|null, errors: array|null}
     */
    public function returnForRevision(
        string $documentType,
        string $documentId,
        User $approver,
        string $comments,
        string $originatorId = '',
        ?string $departmentId = null,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $actor ??= $approver;

        if (trim($comments) === '') {
            throw new InvalidArgumentException('Revision comments are required.');
        }

        [$pendingApproval, $currentLevel, $workflow, $errorResponse] =
            $this->resolvePendingSlot($documentType, $documentId, $approver, $departmentId);

        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            DB::beginTransaction();

            $this->repository->updateApproval($pendingApproval, [
                'action'   => 'returned',
                'comment'  => $comments,
                'acted_at' => now(),
            ]);

            $this->getStatusUpdater()->updateStatus($documentType, $documentId, 'revision_required');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ApprovalWorkflowService::returnForRevision failed', [
                'document_type' => $documentType,
                'document_id'   => $documentId,
                'approver_id'   => $approver->id,
                'error'         => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to return document for revision. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        // Fire event — listeners update document status to 'revision_required'
        DocumentReturnedForRevision::dispatch(
            $documentType,
            $documentId,
            $workflow->tenant_id,
            $comments,
            $approver->id,
        );

        // Notify originator with revision comments
        if ($originatorId) {
            SendApprovalOutcomeNotificationJob::dispatch(
                $workflow->tenant_id,
                $documentType,
                $documentId,
                'revision_required',
                $comments,
                [$originatorId],
            );
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            tenantId:   $workflow->tenant_id,
            actionType: 'document_returned_for_revision',
            entityType: $documentType,
            entityId:   $documentId,
            before:     ['action' => 'pending', 'level_order' => $currentLevel->level_order],
            after:      ['action' => 'returned', 'comments' => $comments],
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Document returned for revision successfully.',
            'code'    => 200,
            'data'    => ['outcome' => 'revision_required', 'level_order' => $currentLevel->level_order],
            'errors'  => null,
        ];
    }

    // =========================================================================
    // 6.9 — processEscalations(): called by hourly scheduler
    // =========================================================================

    /**
     * Scan all pending Approval records across all tenants and dispatch
     * SendEscalationNotificationJob for any that have been pending longer than
     * the configured escalation_hours for their level.
     *
     * Escalation target priority:
     *  1. Approver's supervisor_id (User.supervisor_id column, if populated)
     *  2. Tenant_Admin of the approver's tenant (fallback)
     *
     * This method is intentionally called without a specific tenant context
     * (uses withoutGlobalScopes) so the scheduler can process all tenants.
     *
     * Requirements: 6.9
     *
     * @return int  Number of escalation notifications dispatched
     */
    public function processEscalations(): int
    {
        // We need to evaluate each pending approval against its level's escalation_hours.
        // Fetch all pending approvals with their levels and then filter in PHP to
        // avoid a complex DB join across tenant-scoped models.
        $allPending = Approval::withoutGlobalScopes()
            ->with(['level', 'workflow', 'approver'])
            ->where('action', 'pending')
            ->get();

        $dispatched = 0;

        foreach ($allPending as $approval) {
            $level = $approval->level;

            if (! $level) {
                continue;
            }

            $escalationHours = $level->escalation_hours ?? 48;
            $createdAt       = $approval->created_at;

            // Check if the approval has been pending longer than the configured hours
            if (! $createdAt || now()->diffInHours($createdAt, absolute: true) < $escalationHours) {
                continue;
            }

            $escalationTargetId = $this->resolveEscalationTarget(
                $approval->approver_id,
                $approval->tenant_id,
            );

            if (! $escalationTargetId) {
                Log::warning('ApprovalWorkflowService::processEscalations: no escalation target found', [
                    'approval_id' => $approval->id,
                    'tenant_id'   => $approval->tenant_id,
                ]);
                continue;
            }

            SendEscalationNotificationJob::dispatch(
                $approval->tenant_id,
                $approval->document_type,
                $approval->document_id,
                $approval->id,
                $approval->approver_id,
                $escalationTargetId,
                $escalationHours,
            );

            $dispatched++;
        }

        return $dispatched;
    }

    // =========================================================================
    // Approval history
    // =========================================================================

    /**
     * Retrieve the full approval history for a document.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Approval>
     */
    public function getHistory(string $documentType, string $documentId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->getApprovalHistory($documentType, $documentId);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Locate the pending Approval slot for the given approver on the active level
     * and enforce that the approver is designated to act on this document.
     *
     * Returns a 4-element array:
     *  [Approval, ApprovalWorkflowLevel, ApprovalWorkflow, null]          on success
     *  [null,     null,                  null,             errorResponse]  on failure
     *
     * Requirements: 6.6
     *
     * @return array{0: Approval|null, 1: ApprovalWorkflowLevel|null, 2: ApprovalWorkflow|null, 3: array|null}
     */
    private function resolvePendingSlot(
        string $documentType,
        string $documentId,
        User $approver,
        ?string $departmentId = null,
    ): array {
        $workflow = $this->repository->findActiveWorkflow($documentType, $departmentId);

        if (! $workflow) {
            return [null, null, null, [
                'success' => false,
                'message' => "No active approval workflow configured for document type '{$documentType}'.",
                'code'    => 422,
                'data'    => null,
                'errors'  => ['workflow' => ["No active approval workflow configured for document type '{$documentType}'."]],
            ]];
        }

        // Find the current active level — the lowest level_order that still has pending approvals
        $currentLevel = $this->findCurrentLevel($workflow->id, $documentType, $documentId);

        if (! $currentLevel) {
            return [null, null, null, [
                'success' => false,
                'message' => 'No active approval level found for this document. The workflow may already be complete or not yet started.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['level' => ['No active approval level found for this document.']],
            ]];
        }

        // Find this approver's pending slot at the current level
        $pendingApproval = $this->repository->findPendingApproval(
            approverId:   $approver->id,
            documentType: $documentType,
            documentId:   $documentId,
            levelId:      $currentLevel->id,
        );

        if (! $pendingApproval) {
            return [null, null, null, [
                'success' => false,
                'message' => 'You are not a designated approver for the current level of this document, or you have already acted.',
                'code'    => 403,
                'data'    => null,
                'errors'  => ['approver' => ['You are not a designated approver for the current level of this document.']],
            ]];
        }

        // Enforce canAct(): verify the approver is still the designated approver for this slot
        if (! $this->canAct($pendingApproval, $approver)) {
            throw new UnauthorizedTenantAccessException(
                'You are not authorised to act on this approval at the current level.'
            );
        }

        return [$pendingApproval, $currentLevel, $workflow, null];
    }

    /**
     * Determine the current active level for a document within a workflow.
     *
     * The current level is the level with the lowest level_order that still
     * contains at least one pending Approval record.
     */
    private function findCurrentLevel(
        string $workflowId,
        string $documentType,
        string $documentId,
    ): ?ApprovalWorkflowLevel {
        // Fetch all levels for this workflow ordered by level_order
        $levels = $this->repository->getLevels($workflowId);

        foreach ($levels as $level) {
            $pendingCount = $this->repository->countPendingAtLevel(
                $documentType,
                $documentId,
                $level->id,
            );

            if ($pendingCount > 0) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Determine whether a level is fully approved, taking is_parallel into account.
     *
     * Sequential level (is_parallel = false):
     *  → Advances as soon as the approver records an approval (one is enough).
     *    Since this method is called after the approval is written, remaining
     *    pending slots at the level need to be cancelled/skipped.
     *
     * Parallel level (is_parallel = true):
     *  → Only advances when ALL seeded slots are approved (zero pending remain).
     *
     * Requirements: 6.6
     */
    private function levelIsFullyApproved(
        string $documentType,
        string $documentId,
        ApprovalWorkflowLevel $level,
    ): bool {
        if (! $level->is_parallel) {
            // Sequential: one approval is sufficient — clear remaining pending slots
            $this->cancelRemainingPendingSlots($documentType, $documentId, $level->id);

            return true;
        }

        // Parallel: require all designated approvers to have approved
        $pendingCount = $this->repository->countPendingAtLevel(
            $documentType,
            $documentId,
            $level->id,
        );

        return $pendingCount === 0;
    }

    /**
     * Cancel (set to 'approved' with a system comment) any remaining pending
     * Approval records at a sequential level that has already been approved by
     * one designated approver.  This prevents ghost pending records from
     * blocking future workflow progression.
     */
    private function cancelRemainingPendingSlots(
        string $documentType,
        string $documentId,
        string $levelId,
    ): void {
        Approval::withoutGlobalScopes()
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('level_id', $levelId)
            ->where('action', 'pending')
            ->update([
                'action'   => 'approved',
                'comment'  => 'Auto-approved: level advanced by sequential approval.',
                'acted_at' => now(),
            ]);
    }

    /**
     * Resolve the escalation target for an overdue approver.
     *
     * Priority:
     *  1. approver.supervisor_id  (if the User model has this column)
     *  2. First Tenant_Admin of the approver's tenant
     *
     * Requirements: 6.9
     */
    private function resolveEscalationTarget(string $approverId, string $tenantId): ?string
    {
        $approver = User::withoutGlobalScopes()->find($approverId);

        // Check for a supervisor_id column (soft-dependency — use if column exists)
        if ($approver && isset($approver->supervisor_id) && $approver->supervisor_id) {
            return $approver->supervisor_id;
        }

        // Fallback: first active Tenant_Admin in this tenant
        $admin = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', 'Tenant_Admin'))
            ->first();

        return $admin?->id;
    }

    /**
     * Dispatch an async WriteAuditLogJob for the given approval action.
     */
    private function dispatchAuditLog(
        ?User $actor,
        string $tenantId,
        string $actionType,
        string $entityType,
        string $entityId,
        ?array $before,
        ?array $after,
        string $ipAddress,
        ?string $requestId,
    ): void {
        WriteAuditLogJob::dispatch(
            tenantId:   $tenantId,
            userId:     $actor?->id,
            userRole:   $actor?->getRoleNames()->first(),
            actionType: $actionType,
            entityType: $entityType,
            entityId:   $entityId,
            before:     $before,
            after:      $after,
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );
    }
}
