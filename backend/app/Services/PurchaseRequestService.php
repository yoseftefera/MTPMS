<?php

namespace App\Services;

use App\Events\PurchaseRequestSubmitted;
use App\Exceptions\BudgetExceededException;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestHistory;
use App\Models\PurchaseRequestItem;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\PurchaseRequestRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PurchaseRequestService — full PR lifecycle for a department within a tenant.
 *
 * All monetary arithmetic uses PHP's BCMath extension (bcadd, bcmul) with a
 * scale of 2 decimal places to avoid floating-point errors.
 *
 * PR number format: PR-{TENANT_CODE}-{YEAR}-{SEQUENCE}
 * Example: PR-ACME-2025-00001
 *
 * Allowed status transitions:
 *  - draft             → pending_approval  (submit)
 *  - draft             → cancelled         (cancel)
 *  - pending_approval  → cancelled         (cancel)
 *  - revision_required → pending_approval  (re-submit)
 *  - revision_required → cancelled         (cancel)
 *  (Approve/reject/revision transitions are handled by ApprovalWorkflowService)
 *
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7
 */
class PurchaseRequestService
{
    /**
     * BCMath decimal scale — matches DECIMAL(15,2) database columns.
     */
    private const SCALE = 2;

    /**
     * Statuses from which a PR may be submitted (or re-submitted).
     */
    private const SUBMITTABLE_STATUSES = ['draft', 'revision_required'];

    /**
     * Statuses from which a PR may be cancelled.
     */
    private const CANCELLABLE_STATUSES = ['draft', 'pending_approval', 'revision_required'];

    public function __construct(
        private readonly PurchaseRequestRepositoryInterface $repository,
        private readonly BudgetService $budgetService,
    ) {}

    // -------------------------------------------------------------------------
    // PR Number Generation
    // -------------------------------------------------------------------------

    /**
     * Generate the next sequential PR number for the given tenant and year.
     *
     * Format: PR-{TENANT_CODE}-{YEAR}-{SEQUENCE}  (SEQUENCE zero-padded to 5 digits)
     * Example: PR-ACME-2025-00001
     *
     * A pessimistic lock (SELECT … FOR UPDATE) on the purchase_requests table is
     * used inside a database transaction to prevent duplicate sequences under
     * concurrent submissions.
     *
     * Requirements: 5.1
     */
    public function generatePRNumber(Tenant $tenant, int $year = 0): string
    {
        if ($year === 0) {
            $year = now()->year;
        }

        $tenantCode = strtoupper($tenant->tenant_code);

        $sequence = DB::transaction(function () use ($tenant, $year) {
            // Lock all existing PR rows for this tenant+year so concurrent
            // transactions must wait, guaranteeing a unique sequence number.
            $count = DB::table('purchase_requests')
                ->where('tenant_id', $tenant->id)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();

            return $count + 1;
        });

        return sprintf('PR-%s-%d-%05d', $tenantCode, $year, $sequence);
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a new Purchase Request in draft status.
     *
     * Validation rules:
     *  - title is required
     *  - department_id is required
     *  - items must be a non-empty array
     *  - each item must have: description, quantity, unit_of_measure, estimated_unit_price
     *
     * Calculates estimated_total = Σ(quantity × estimated_unit_price) using BCMath.
     * Records a 'created' history entry. Dispatches PurchaseRequestSubmitted event.
     *
     * Requirements: 5.1, 5.2, 5.7
     *
     * @param  array{
     *     title: string,
     *     department_id: string,
     *     description?: string,
     *     required_date?: string,
     *     currency?: string,
     *     items: array<int, array{
     *         description: string,
     *         quantity: string|int|float,
     *         unit_of_measure: string,
     *         estimated_unit_price: string|int|float,
     *         budget_code?: string,
     *     }>,
     * }  $data
     *
     * @throws InvalidArgumentException  when required fields are missing or items array is empty
     */
    public function create(array $data, User $submitter): PurchaseRequest
    {
        $this->validateCreateData($data);

        return DB::transaction(function () use ($data, $submitter) {
            // Resolve the tenant; fall back to app('tenant') when not set on user
            $tenant = $submitter->tenant ?? app('tenant');

            $prNumber      = $this->generatePRNumber($tenant);
            $estimatedTotal = $this->calculateTotal($data['items']);

            /** @var PurchaseRequest $pr */
            $pr = $this->repository->create([
                'pr_number'       => $prNumber,
                'tenant_id'       => $submitter->tenant_id ?? $tenant->id,
                'department_id'   => $data['department_id'],
                'submitted_by'    => $submitter->id,
                'status'          => 'draft',
                'title'           => $data['title'],
                'description'     => $data['description'] ?? null,
                'estimated_total' => $estimatedTotal,
                'currency'        => $data['currency'] ?? 'USD',
                'required_date'   => $data['required_date'] ?? null,
            ]);

            $this->createItems($pr, $data['items']);

            $this->recordHistory($pr, 'created', null, 'draft', $submitter->id);

            return $pr->load(['items', 'department', 'submittedBy', 'history']);
        });
    }

    // -------------------------------------------------------------------------
    // Update (draft only)
    // -------------------------------------------------------------------------

    /**
     * Update a Purchase Request that is currently in draft status.
     *
     * Allowed updates: title, description, required_date, items (full replacement).
     * If items are provided they completely replace the existing line items.
     * Recalculates estimated_total from the new items set.
     *
     * Requirements: 5.2, 5.5, 5.7
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException  when PR is not in draft status
     * @throws InvalidArgumentException  when items array is provided but empty
     */
    public function update(PurchaseRequest $pr, array $data, User $updater): PurchaseRequest
    {
        if ($pr->status !== 'draft') {
            throw new InvalidArgumentException(
                "Purchase request {$pr->pr_number} cannot be updated: only draft PRs may be edited "
                . "(current status: {$pr->status})."
            );
        }

        if (isset($data['items'])) {
            if (empty($data['items'])) {
                throw new InvalidArgumentException(
                    'Purchase request must contain at least one line item.'
                );
            }

            $this->validateItems($data['items']);
        }

        return DB::transaction(function () use ($pr, $data, $updater) {
            $updatePayload = [];

            if (isset($data['title'])) {
                $updatePayload['title'] = $data['title'];
            }

            if (array_key_exists('description', $data)) {
                $updatePayload['description'] = $data['description'];
            }

            if (array_key_exists('required_date', $data)) {
                $updatePayload['required_date'] = $data['required_date'];
            }

            if (isset($data['items'])) {
                // Full replacement: delete existing items, recreate from new set
                PurchaseRequestItem::where('purchase_request_id', $pr->id)->delete();
                $this->createItems($pr, $data['items']);
                $updatePayload['estimated_total'] = $this->calculateTotal($data['items']);
            }

            if (! empty($updatePayload)) {
                $pr = $this->repository->update($pr, $updatePayload);
            }

            $this->recordHistory($pr, 'updated', $pr->status, $pr->status, $updater->id);

            return $pr->load(['items', 'department', 'submittedBy', 'history']);
        });
    }

    // -------------------------------------------------------------------------
    // Submit
    // -------------------------------------------------------------------------

    /**
     * Submit a Purchase Request for approval.
     *
     * Allowed from: draft, revision_required
     * Validates the PR's estimated_total against the department's available budget.
     * Transitions status to pending_approval, sets submitted_at = now().
     * Dispatches PurchaseRequestSubmitted event (triggers approval workflow).
     *
     * Requirements: 5.3, 5.4, 5.6, 5.7
     *
     * @throws InvalidArgumentException    when PR is not in a submittable status
     * @throws BudgetExceededException     when estimated_total exceeds available budget
     */
    public function submit(PurchaseRequest $pr, User $submitter): PurchaseRequest
    {
        if (! in_array($pr->status, self::SUBMITTABLE_STATUSES, true)) {
            throw new InvalidArgumentException(
                "Purchase request {$pr->pr_number} cannot be submitted: only PRs in 'draft' or "
                . "'revision_required' status may be submitted (current status: {$pr->status})."
            );
        }

        // Validate budget availability — throws BudgetExceededException on failure
        $this->budgetService->validatePRAgainstBudget($pr);

        return DB::transaction(function () use ($pr, $submitter) {
            $fromStatus = $pr->status;

            $pr = $this->repository->update($pr, [
                'status'       => 'pending_approval',
                'submitted_at' => now(),
            ]);

            $this->recordHistory($pr, 'submitted', $fromStatus, 'pending_approval', $submitter->id);

            PurchaseRequestSubmitted::dispatch($pr);

            return $pr->load(['items', 'department', 'submittedBy', 'history']);
        });
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    /**
     * Cancel a Purchase Request.
     *
     * Allowed from: draft, pending_approval, revision_required
     * Records the cancellation reason in the history comment.
     *
     * Requirements: 5.7
     *
     * @throws InvalidArgumentException  when PR cannot be cancelled from its current status
     */
    public function cancel(PurchaseRequest $pr, User $canceller, string $reason = ''): PurchaseRequest
    {
        if (! in_array($pr->status, self::CANCELLABLE_STATUSES, true)) {
            $allowed = implode(', ', self::CANCELLABLE_STATUSES);
            throw new InvalidArgumentException(
                "Purchase request {$pr->pr_number} cannot be cancelled from status '{$pr->status}'. "
                . "Cancellation is allowed from: {$allowed}."
            );
        }

        return DB::transaction(function () use ($pr, $canceller, $reason) {
            $fromStatus = $pr->status;

            $pr = $this->repository->update($pr, ['status' => 'cancelled']);

            $this->recordHistory(
                pr:          $pr,
                action:      'cancelled',
                fromStatus:  $fromStatus,
                toStatus:    'cancelled',
                performedBy: $canceller->id,
                comment:     $reason,
            );

            return $pr->load(['items', 'department', 'submittedBy', 'history']);
        });
    }

    // -------------------------------------------------------------------------
    // Search / List
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of purchase requests within the active tenant scope,
     * with optional filters applied.
     *
     * Eager-loads: department, submittedBy, items
     *
     * Requirements: 5.8
     *
     * @param  array<string, mixed>  $filters  Supported: pr_number, department_id,
     *                                          status, date_from, date_to, submitted_by
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate required fields for the create() method.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private function validateCreateData(array $data): void
    {
        if (empty($data['title'])) {
            throw new InvalidArgumentException('The title field is required.');
        }

        if (empty($data['department_id'])) {
            throw new InvalidArgumentException('The department_id field is required.');
        }

        if (empty($data['items']) || ! is_array($data['items'])) {
            throw new InvalidArgumentException(
                'Purchase request must contain at least one line item.'
            );
        }

        $this->validateItems($data['items']);
    }

    /**
     * Validate each item in the items array.
     *
     * Required per item: description, quantity, unit_of_measure, estimated_unit_price
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws InvalidArgumentException
     */
    private function validateItems(array $items): void
    {
        foreach ($items as $index => $item) {
            $position = $index + 1;

            if (empty($item['description'])) {
                throw new InvalidArgumentException(
                    "Item {$position}: the 'description' field is required."
                );
            }

            if (! isset($item['quantity']) || $item['quantity'] === '') {
                throw new InvalidArgumentException(
                    "Item {$position}: the 'quantity' field is required."
                );
            }

            if (empty($item['unit_of_measure'])) {
                throw new InvalidArgumentException(
                    "Item {$position}: the 'unit_of_measure' field is required."
                );
            }

            if (! isset($item['estimated_unit_price']) || $item['estimated_unit_price'] === '') {
                throw new InvalidArgumentException(
                    "Item {$position}: the 'estimated_unit_price' field is required."
                );
            }
        }
    }

    /**
     * Bulk-create PurchaseRequestItem records for a given PR.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createItems(PurchaseRequest $pr, array $items): void
    {
        $records = array_map(function (array $item) use ($pr) {
            return [
                'purchase_request_id'  => $pr->id,
                'tenant_id'            => $pr->tenant_id,
                'description'          => $item['description'],
                'quantity'             => (string) $item['quantity'],
                'unit_of_measure'      => $item['unit_of_measure'],
                'estimated_unit_price' => (string) $item['estimated_unit_price'],
                'budget_code'          => $item['budget_code'] ?? null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }, $items);

        // Use chunk inserts to avoid hitting MySQL's placeholder limit for large sets
        foreach (array_chunk($records, 500) as $chunk) {
            PurchaseRequestItem::insert($chunk);
        }
    }

    /**
     * Calculate the estimated total for a set of line items using BCMath.
     *
     * estimated_total = Σ(quantity × estimated_unit_price)  [scale = 2]
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function calculateTotal(array $items): string
    {
        $total = '0.00';

        foreach ($items as $item) {
            $lineTotal = bcmul(
                (string) $item['quantity'],
                (string) $item['estimated_unit_price'],
                self::SCALE,
            );

            $total = bcadd($total, $lineTotal, self::SCALE);
        }

        return $total;
    }

    /**
     * Record a history entry for a PR status transition or action.
     * History entries are always recorded — never skipped.
     */
    private function recordHistory(
        PurchaseRequest $pr,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        string $performedBy,
        string $comment = '',
    ): void {
        PurchaseRequestHistory::create([
            'purchase_request_id' => $pr->id,
            'tenant_id'           => $pr->tenant_id,
            'action'              => $action,
            'from_status'         => $fromStatus,
            'to_status'           => $toStatus,
            'comment'             => $comment ?: null,
            'performed_by'        => $performedBy,
            'created_at'          => now(),
        ]);
    }
}
