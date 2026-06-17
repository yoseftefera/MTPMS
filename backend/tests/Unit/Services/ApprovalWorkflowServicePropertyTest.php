<?php

/**
 * Property-Based Tests for ApprovalWorkflowService.
 *
 * Property 8 — State Machine Progression (100 iterations):
 *   For any L-level workflow (L randomly chosen from 1–10):
 *   - Approval at level k where k < L: document advances to level k+1
 *     (pending approvals exist at k+1, none at k)
 *   - Approval at final level L: document status transitions to 'approved'
 *   - Rejection at any level k (1 ≤ k ≤ L): document status transitions to 'rejected'
 *   - Return at any level k (1 ≤ k ≤ L): document status transitions to 'revision_required'
 *
 * **Validates: Requirements 6.3, 6.4, 6.5, 21.5**
 *
 * @group property-based
 */

use App\Models\Approval;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowLevel;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\ApprovalWorkflowRepository;
use App\Services\ApprovalWorkflowService;
use App\Services\DocumentStatusUpdater;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Build the service under test with real repository and status updater.
 */
function makeWorkflowService(): ApprovalWorkflowService
{
    return new ApprovalWorkflowService(
        new ApprovalWorkflowRepository(),
        new DocumentStatusUpdater(),
    );
}

/**
 * Create a Tenant, set it as the active tenant, and return it.
 */
function createActiveTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);
    return $tenant;
}

/**
 * Create a Department belonging to the given tenant.
 * Uses forceFill() + a random suffix to bypass the DepartmentFactory's
 * unique() constraint that exhausts after ~12 iterations.
 */
function createDepartment(Tenant $tenant): Department
{
    $suffix = Str::random(10);
    $dept   = new Department();
    $dept->forceFill([
        'id'        => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'name'      => 'Test Dept ' . $suffix,
        'code'      => strtoupper(Str::random(8)),
        'parent_id' => null,
        'status'    => 'active',
    ]);
    $dept->save();

    return $dept;
}

/**
 * Create a User belonging to the given tenant.
 */
function createApprover(Tenant $tenant): User
{
    return User::factory()->forTenant($tenant)->create(['status' => 'active']);
}

/**
 * Create an L-level sequential workflow for 'purchase_request' on the given tenant.
 * Each level is assigned to the corresponding approver in $approvers[].
 *
 * Uses forceFill() to bypass the $fillable restriction for id/tenant_id.
 *
 * @param  Tenant        $tenant
 * @param  list<User>    $approvers  One User per level (count must equal $levels)
 * @param  int           $levels     Number of levels
 * @return ApprovalWorkflow
 */
function createWorkflowWithLevels(Tenant $tenant, array $approvers, int $levels): ApprovalWorkflow
{
    $workflowId = (string) Str::uuid();

    /** @var ApprovalWorkflow $workflow */
    $workflow = new ApprovalWorkflow();
    $workflow->forceFill([
        'id'            => $workflowId,
        'tenant_id'     => $tenant->id,
        'name'          => "Test Workflow ({$levels} levels)",
        'document_type' => 'purchase_request',
        'department_id' => null,
        'is_active'     => true,
    ]);
    $workflow->save();

    for ($i = 0; $i < $levels; $i++) {
        $level = new ApprovalWorkflowLevel();
        $level->forceFill([
            'id'               => (string) Str::uuid(),
            'tenant_id'        => $tenant->id,
            'workflow_id'      => $workflowId,
            'level_order'      => $i + 1,
            'approver_type'    => 'user',
            'approver_role'    => null,
            'approver_user_id' => $approvers[$i]->id,
            'is_parallel'      => false,
            'escalation_hours' => 48,
        ]);
        $level->save();
    }

    return $workflow;
}

/**
 * Create a PurchaseRequest in 'pending_approval' status for the given tenant and user.
 * Uses forceFill() to bypass the $fillable restriction for id/tenant_id.
 */
function createPendingPR(Tenant $tenant, User $submitter, Department $department): PurchaseRequest
{
    $year = now()->year;
    $seq  = str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);

    $pr = new PurchaseRequest();
    $pr->forceFill([
        'id'              => (string) Str::uuid(),
        'tenant_id'       => $tenant->id,
        'pr_number'       => "PR-TEST-{$year}-{$seq}",
        'department_id'   => $department->id,
        'submitted_by'    => $submitter->id,
        'status'          => 'pending_approval',
        'title'           => 'Property Test PR',
        'description'     => null,
        'estimated_total' => '1000.00',
        'currency'        => 'USD',
        'required_date'   => null,
        'submitted_at'    => now(),
    ]);
    $pr->save();

    return $pr;
}

/**
 * Seed the first level of the workflow for the document (simulates initiate()).
 * Uses forceFill() to bypass the $fillable restriction for id/tenant_id.
 */
function seedLevel(ApprovalWorkflow $workflow, ApprovalWorkflowLevel $level, string $documentId): void
{
    Approval::withoutGlobalScopes()
        ->where('workflow_id', $workflow->id)
        ->where('level_id', $level->id)
        ->where('document_id', $documentId)
        ->where('action', 'pending')
        ->delete();

    $approval = new Approval();
    $approval->forceFill([
        'id'            => (string) Str::uuid(),
        'tenant_id'     => $workflow->tenant_id,
        'workflow_id'   => $workflow->id,
        'level_id'      => $level->id,
        'document_type' => 'purchase_request',
        'document_id'   => $documentId,
        'approver_id'   => $level->approver_user_id,
        'action'        => 'pending',
        'comment'       => null,
        'acted_at'      => null,
    ]);
    $approval->save();
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Bus::fake();
    Event::fake();
});

// ---------------------------------------------------------------------------
// Property 8A — Intermediate approval advances to next level
// Validates: Requirements 6.3, 21.5
// ---------------------------------------------------------------------------

it('Property 8A: approval at level k < L advances the document to level k+1 across 100 random iterations', function () {

    for ($iteration = 0; $iteration < 100; $iteration++) {
        // Random L from 2–10 (need at least 2 so there is a "next level")
        $L = mt_rand(2, 10);

        // Create a fresh tenant per iteration to avoid scope pollution
        $tenant = createActiveTenant();

        // Create L approvers
        $approvers = [];
        for ($i = 0; $i < $L; $i++) {
            $approvers[] = createApprover($tenant);
        }

        $workflow = createWorkflowWithLevels($tenant, $approvers, $L);

        // Load ordered levels
        $levels = ApprovalWorkflowLevel::withoutGlobalScopes()
            ->where('workflow_id', $workflow->id)
            ->orderBy('level_order')
            ->get();

        // Pick a random intermediate level index (0-indexed), 0 to L-2
        $kIndex  = mt_rand(0, $L - 2);       // 0-indexed
        $kLevel  = $levels[$kIndex];          // Level at position k (1-indexed = kIndex+1)
        $k1Level = $levels[$kIndex + 1];      // Level at position k+1

        // Create PR
        $submitter  = createApprover($tenant);
        $department = createDepartment($tenant);
        $pr         = createPendingPR($tenant, $submitter, $department);

        // Walk through levels 1..k-1 (approve them to get into position)
        for ($i = 0; $i < $kIndex; $i++) {
            $level    = $levels[$i];
            $approver = $approvers[$i];

            seedLevel($workflow, $level, $pr->id);

            $service = makeWorkflowService();
            $result  = $service->advance(
                documentType: 'purchase_request',
                documentId:   $pr->id,
                approver:     $approver,
                comment:      null,
                originatorId: $submitter->id,
            );

            expect($result['success'])->toBeTrue(
                "Iter {$iteration}: walk-through at level_order={$level->level_order} failed: {$result['message']}"
            );
        }

        // Now seed level k and advance it
        seedLevel($workflow, $kLevel, $pr->id);

        $service = makeWorkflowService();
        $result  = $service->advance(
            documentType: 'purchase_request',
            documentId:   $pr->id,
            approver:     $approvers[$kIndex],
            comment:      null,
            originatorId: $submitter->id,
        );

        expect($result['success'])->toBeTrue(
            "Iter {$iteration}: advance at level_order={$kLevel->level_order} (k<L) failed: {$result['message']}"
        );
        expect($result['data']['outcome'])->toBe(
            'advanced',
            "Iter {$iteration}: expected outcome='advanced' at k={$kLevel->level_order}, L={$L}."
        );

        // Assert: pending approvals exist at level k+1
        $pendingAtK1 = Approval::withoutGlobalScopes()
            ->where('document_type', 'purchase_request')
            ->where('document_id', $pr->id)
            ->where('level_id', $k1Level->id)
            ->where('action', 'pending')
            ->count();

        expect($pendingAtK1)->toBeGreaterThan(
            0,
            "Iter {$iteration}: expected pending approvals at level k+1 (order={$k1Level->level_order}) after advancing from level k (order={$kLevel->level_order}), found 0."
        );

        // Assert: no pending approvals remain at level k
        $pendingAtK = Approval::withoutGlobalScopes()
            ->where('document_type', 'purchase_request')
            ->where('document_id', $pr->id)
            ->where('level_id', $kLevel->id)
            ->where('action', 'pending')
            ->count();

        expect($pendingAtK)->toBe(
            0,
            "Iter {$iteration}: expected 0 pending approvals at level k (order={$kLevel->level_order}) after it was approved, found {$pendingAtK}."
        );
    }

    expect(true)->toBeTrue('Property 8A: all 100 intermediate-advance iterations completed.');
});

// ---------------------------------------------------------------------------
// Property 8B — Final approval transitions status to 'approved'
// Validates: Requirements 6.3, 21.5
// ---------------------------------------------------------------------------

it('Property 8B: approval at the final level L transitions document status to approved across 100 random iterations', function () {

    for ($iteration = 0; $iteration < 100; $iteration++) {
        // Random L from 1–10
        $L = mt_rand(1, 10);

        $tenant = createActiveTenant();

        $approvers = [];
        for ($i = 0; $i < $L; $i++) {
            $approvers[] = createApprover($tenant);
        }

        $workflow = createWorkflowWithLevels($tenant, $approvers, $L);

        $levels = ApprovalWorkflowLevel::withoutGlobalScopes()
            ->where('workflow_id', $workflow->id)
            ->orderBy('level_order')
            ->get();

        $submitter  = createApprover($tenant);
        $department = createDepartment($tenant);
        $pr         = createPendingPR($tenant, $submitter, $department);

        // Walk through levels 1 through L, approving each
        for ($i = 0; $i < $L; $i++) {
            $level    = $levels[$i];
            $approver = $approvers[$i];

            seedLevel($workflow, $level, $pr->id);

            $service = makeWorkflowService();
            $result  = $service->advance(
                documentType: 'purchase_request',
                documentId:   $pr->id,
                approver:     $approver,
                comment:      null,
                originatorId: $submitter->id,
            );

            expect($result['success'])->toBeTrue(
                "Iter {$iteration}: advance at level_order={$level->level_order} failed: {$result['message']}"
            );
        }

        // After advancing through the final level, the document must be 'approved'
        $pr->refresh();

        expect($pr->status)->toBe(
            'approved',
            "Iter {$iteration}: after approving all {$L} levels, document status should be 'approved', got '{$pr->status}'."
        );
    }

    expect(true)->toBeTrue('Property 8B: all 100 final-approval iterations completed.');
});

// ---------------------------------------------------------------------------
// Property 8C — Rejection at any level transitions status to 'rejected'
// Validates: Requirements 6.4, 21.5
// ---------------------------------------------------------------------------

it('Property 8C: rejection at any level k transitions document status to rejected across 100 random iterations', function () {

    for ($iteration = 0; $iteration < 100; $iteration++) {
        // Random L from 1–10
        $L = mt_rand(1, 10);

        $tenant = createActiveTenant();

        $approvers = [];
        for ($i = 0; $i < $L; $i++) {
            $approvers[] = createApprover($tenant);
        }

        $workflow = createWorkflowWithLevels($tenant, $approvers, $L);

        $levels = ApprovalWorkflowLevel::withoutGlobalScopes()
            ->where('workflow_id', $workflow->id)
            ->orderBy('level_order')
            ->get();

        $submitter  = createApprover($tenant);
        $department = createDepartment($tenant);
        $pr         = createPendingPR($tenant, $submitter, $department);

        // Pick a random rejection level (0-indexed)
        $kIndex       = mt_rand(0, $L - 1);
        $rejectLevel  = $levels[$kIndex];

        // Walk through and approve levels 1..k-1
        for ($i = 0; $i < $kIndex; $i++) {
            $level    = $levels[$i];
            $approver = $approvers[$i];

            seedLevel($workflow, $level, $pr->id);

            $service = makeWorkflowService();
            $result  = $service->advance(
                documentType: 'purchase_request',
                documentId:   $pr->id,
                approver:     $approver,
                comment:      null,
                originatorId: $submitter->id,
            );

            expect($result['success'])->toBeTrue(
                "Iter {$iteration}: walk-through advance at level_order={$level->level_order} failed: {$result['message']}"
            );
        }

        // Seed level k and reject at it
        seedLevel($workflow, $rejectLevel, $pr->id);

        $service = makeWorkflowService();
        $result  = $service->reject(
            documentType: 'purchase_request',
            documentId:   $pr->id,
            approver:     $approvers[$kIndex],
            reason:       'Property test rejection reason.',
            originatorId: $submitter->id,
        );

        expect($result['success'])->toBeTrue(
            "Iter {$iteration}: reject() at level_order={$rejectLevel->level_order} failed: {$result['message']}"
        );

        $pr->refresh();

        expect($pr->status)->toBe(
            'rejected',
            "Iter {$iteration}: after rejection at level_order={$rejectLevel->level_order}, document status should be 'rejected', got '{$pr->status}'."
        );
    }

    expect(true)->toBeTrue('Property 8C: all 100 rejection iterations completed.');
});

// ---------------------------------------------------------------------------
// Property 8D — Return at any level transitions status to 'revision_required'
// Validates: Requirements 6.5, 21.5
// ---------------------------------------------------------------------------

it('Property 8D: return at any level k transitions document status to revision_required across 100 random iterations', function () {

    for ($iteration = 0; $iteration < 100; $iteration++) {
        // Random L from 1–10
        $L = mt_rand(1, 10);

        $tenant = createActiveTenant();

        $approvers = [];
        for ($i = 0; $i < $L; $i++) {
            $approvers[] = createApprover($tenant);
        }

        $workflow = createWorkflowWithLevels($tenant, $approvers, $L);

        $levels = ApprovalWorkflowLevel::withoutGlobalScopes()
            ->where('workflow_id', $workflow->id)
            ->orderBy('level_order')
            ->get();

        $submitter  = createApprover($tenant);
        $department = createDepartment($tenant);
        $pr         = createPendingPR($tenant, $submitter, $department);

        // Pick a random return level (0-indexed)
        $kIndex      = mt_rand(0, $L - 1);
        $returnLevel = $levels[$kIndex];

        // Walk through and approve levels 1..k-1
        for ($i = 0; $i < $kIndex; $i++) {
            $level    = $levels[$i];
            $approver = $approvers[$i];

            seedLevel($workflow, $level, $pr->id);

            $service = makeWorkflowService();
            $result  = $service->advance(
                documentType: 'purchase_request',
                documentId:   $pr->id,
                approver:     $approver,
                comment:      null,
                originatorId: $submitter->id,
            );

            expect($result['success'])->toBeTrue(
                "Iter {$iteration}: walk-through advance at level_order={$level->level_order} failed: {$result['message']}"
            );
        }

        // Seed level k and return it for revision
        seedLevel($workflow, $returnLevel, $pr->id);

        $service = makeWorkflowService();
        $result  = $service->returnForRevision(
            documentType: 'purchase_request',
            documentId:   $pr->id,
            approver:     $approvers[$kIndex],
            comments:     'Property test revision comments.',
            originatorId: $submitter->id,
        );

        expect($result['success'])->toBeTrue(
            "Iter {$iteration}: returnForRevision() at level_order={$returnLevel->level_order} failed: {$result['message']}"
        );

        $pr->refresh();

        expect($pr->status)->toBe(
            'revision_required',
            "Iter {$iteration}: after return at level_order={$returnLevel->level_order}, document status should be 'revision_required', got '{$pr->status}'."
        );
    }

    expect(true)->toBeTrue('Property 8D: all 100 return-for-revision iterations completed.');
});
