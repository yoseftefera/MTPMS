<?php

namespace App\Services;

use App\Exceptions\BudgetExceededException;
use App\Jobs\SendBudgetThresholdNotificationJob;
use App\Jobs\WriteAuditLogJob;
use App\Models\Budget;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BudgetService — full budget lifecycle for a department within a tenant.
 *
 * All monetary arithmetic uses PHP's BCMath extension (bcadd, bcsub, bccomp,
 * bcmul, bcdiv) with a scale of 2 decimal places to avoid floating-point errors.
 *
 * Core operations:
 *  - allocateBudget()       — create annual budget allocation per department
 *  - validatePRAgainstBudget() — check available balance before PR submission
 *  - encumberAmount()       — reserve funds when a PO is issued
 *  - releaseEncumbrance()   — free reserved funds when PO is cancelled/rejected
 *  - recordExpenditure()    — convert encumbrance to actual spend on invoice approval
 *  - transferBudget()       — move funds between two departments
 *  - getUtilizationReport() — real-time per-department utilization
 *
 * Threshold notifications (75 %, 90 %) are dispatched as queued jobs after
 * every write operation that changes encumbered_amount or spent_amount.
 *
 * Requirements: 13.1, 13.2, 13.3, 13.4, 13.5, 13.6, 13.7, 13.8, 13.9, 13.10
 */
class BudgetService
{
    /**
     * BCMath decimal scale (2 decimal places, matching DECIMAL(15,2) columns).
     */
    private const SCALE = 2;

    // -------------------------------------------------------------------------
    // 13.1 — Budget Allocation
    // -------------------------------------------------------------------------

    /**
     * Create (or replace) the annual budget allocation for a department.
     *
     * A unique constraint `(tenant_id, department_id, fiscal_year)` is enforced
     * at the database level; if a budget already exists for the given combination
     * this method updates the total_amount (Finance_Officer privilege assumed by
     * the caller).
     *
     * @param  array{
     *     department_id: string,
     *     fiscal_year: int,
     *     total_amount: string,
     *     currency?: string,
     * }  $data
     * @param  User|null   $actor
     * @param  string      $ipAddress
     * @param  string|null $requestId
     *
     * @return array{success: bool, message: string, code: int, data: Budget|null, errors: array|null}
     */
    public function allocateBudget(
        array $data,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        // Validate amount is a positive decimal
        if (bccomp($data['total_amount'], '0.00', self::SCALE) <= 0) {
            return [
                'success' => false,
                'message' => 'Total amount must be greater than zero.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['total_amount' => ['Total amount must be greater than zero.']],
            ];
        }

        // Validate department belongs to this tenant
        $department = Department::find($data['department_id']);
        if (! $department) {
            return [
                'success' => false,
                'message' => 'Department not found.',
                'code'    => 404,
                'data'    => null,
                'errors'  => ['department_id' => ['Department not found.']],
            ];
        }

        $tenant = app('tenant');

        $existing = Budget::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('department_id', $data['department_id'])
            ->where('fiscal_year', $data['fiscal_year'])
            ->first();

        try {
            DB::beginTransaction();

            if ($existing) {
                $before = $existing->only(['total_amount', 'fiscal_year']);
                $existing->update([
                    'total_amount' => $data['total_amount'],
                    'currency'     => $data['currency'] ?? $existing->currency,
                ]);
                $budget = $existing->fresh();
                $action = 'budget_reallocated';
            } else {
                $budget = Budget::create([
                    'department_id'    => $data['department_id'],
                    'fiscal_year'      => $data['fiscal_year'],
                    'currency'         => $data['currency'] ?? 'USD',
                    'total_amount'     => $data['total_amount'],
                    'encumbered_amount'=> '0.00',
                    'spent_amount'     => '0.00',
                    'created_by'       => $actor?->id,
                ]);
                $before = null;
                $action = 'budget_allocated';
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BudgetService::allocateBudget failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to allocate budget. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: $action,
            entityId:   $budget->id,
            before:     $before,
            after:      $budget->only(['total_amount', 'encumbered_amount', 'spent_amount', 'fiscal_year']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => $existing ? 'Budget updated successfully.' : 'Budget allocated successfully.',
            'code'    => $existing ? 200 : 201,
            'data'    => $budget->load('department'),
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // 13.2 / 13.3 — PR Validation
    // -------------------------------------------------------------------------

    /**
     * Validate that a purchase request's estimated total does not exceed the
     * department's available budget balance for the fiscal year.
     *
     * Available balance = total_amount − encumbered_amount − spent_amount
     *
     * Throws BudgetExceededException when the amount exceeds the available balance
     * unless an over-budget exception flag is set (Finance_Officer override).
     *
     * Requirements: 13.2, 13.3, 13.9
     *
     * @param  PurchaseRequest  $pr
     * @param  bool             $allowOverBudget  Finance_Officer exception override
     *
     * @throws BudgetExceededException
     */
    public function validatePRAgainstBudget(
        PurchaseRequest $pr,
        bool $allowOverBudget = false,
    ): void {
        $budget = $this->getActiveBudgetForDepartment(
            departmentId: $pr->department_id,
            fiscalYear:   now()->year,
        );

        if (! $budget) {
            // No budget configured — block by default to enforce fiscal discipline
            throw new BudgetExceededException(
                availableBalance: '0.00',
                requestedAmount:  (string) $pr->estimated_total,
                shortfall:        (string) $pr->estimated_total,
            );
        }

        $available = $this->computeAvailable($budget);
        $requested = $this->normalise($pr->estimated_total);

        // If over-budget exception is explicitly approved, skip enforcement
        if ($allowOverBudget) {
            return;
        }

        if (bccomp($requested, $available, self::SCALE) > 0) {
            $shortfall = bcsub($requested, $available, self::SCALE);

            throw new BudgetExceededException(
                availableBalance: $available,
                requestedAmount:  $requested,
                shortfall:        $shortfall,
            );
        }
    }

    // -------------------------------------------------------------------------
    // 13.4 — Encumber Amount (PO issued)
    // -------------------------------------------------------------------------

    /**
     * Reserve (encumber) a monetary amount from the department's budget when
     * a Purchase Order is issued.
     *
     * Prevents any operation that would cause
     *   encumbered_amount + spent_amount > total_amount
     * unless allowOverBudget is true (Finance_Officer exception).
     *
     * Requirements: 13.4, 13.9
     *
     * @param  Budget       $budget
     * @param  string       $amount          Positive decimal string
     * @param  string       $referenceType   e.g. 'purchase_order'
     * @param  string       $referenceId     UUID of the PO
     * @param  bool         $allowOverBudget Finance_Officer override
     * @param  User|null    $actor
     * @param  string       $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: Budget|null, errors: array|null}
     */
    public function encumberAmount(
        Budget $budget,
        string $amount,
        string $referenceType,
        string $referenceId,
        bool $allowOverBudget = false,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $amount = $this->normalise($amount);

        if (bccomp($amount, '0.00', self::SCALE) <= 0) {
            return [
                'success' => false,
                'message' => 'Encumbrance amount must be greater than zero.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['amount' => ['Encumbrance amount must be greater than zero.']],
            ];
        }

        // Enforce 100 % cap unless Finance_Officer override
        if (! $allowOverBudget) {
            $available = $this->computeAvailable($budget);

            if (bccomp($amount, $available, self::SCALE) > 0) {
                $shortfall = bcsub($amount, $available, self::SCALE);

                return [
                    'success' => false,
                    'message' => "Encumbrance of {$amount} exceeds available balance {$available} (shortfall: {$shortfall}).",
                    'code'    => 422,
                    'data'    => null,
                    'errors'  => [
                        'amount' => [
                            "Encumbrance of {$amount} exceeds available balance {$available} (shortfall: {$shortfall}).",
                        ],
                    ],
                ];
            }
        }

        try {
            DB::beginTransaction();

            $before = $budget->only(['encumbered_amount', 'spent_amount', 'total_amount']);

            $newEncumbered = bcadd(
                $this->normalise($budget->encumbered_amount),
                $amount,
                self::SCALE,
            );

            $budget->update(['encumbered_amount' => $newEncumbered]);

            BudgetTransaction::create([
                'budget_id'      => $budget->id,
                'type'           => 'encumber',
                'amount'         => $amount,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'created_by'     => $actor?->id,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BudgetService::encumberAmount failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to encumber amount. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'budget_encumbered',
            entityId:   $budget->id,
            before:     $before,
            after:      $budget->fresh()->only(['encumbered_amount', 'spent_amount', 'total_amount']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        // Check and dispatch threshold notifications
        $this->checkAndDispatchThresholdNotifications($budget->fresh(), $actor?->tenant_id ?? app('tenant')->id);

        return [
            'success' => true,
            'message' => 'Amount encumbered successfully.',
            'code'    => 200,
            'data'    => $budget->fresh(),
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // 13.5 — Release Encumbrance (PO cancelled/rejected)
    // -------------------------------------------------------------------------

    /**
     * Release a previously encumbered amount back to the available balance
     * when a Purchase Order is cancelled or rejected.
     *
     * Requirements: 13.5
     *
     * @param  Budget       $budget
     * @param  string       $amount
     * @param  string       $referenceType
     * @param  string       $referenceId
     * @param  User|null    $actor
     * @param  string       $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: Budget|null, errors: array|null}
     */
    public function releaseEncumbrance(
        Budget $budget,
        string $amount,
        string $referenceType,
        string $referenceId,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $amount = $this->normalise($amount);

        if (bccomp($amount, '0.00', self::SCALE) <= 0) {
            return [
                'success' => false,
                'message' => 'Release amount must be greater than zero.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['amount' => ['Release amount must be greater than zero.']],
            ];
        }

        $currentEncumbered = $this->normalise($budget->encumbered_amount);

        // Guard: cannot release more than what is encumbered
        if (bccomp($amount, $currentEncumbered, self::SCALE) > 0) {
            return [
                'success' => false,
                'message' => "Cannot release {$amount}; only {$currentEncumbered} is currently encumbered.",
                'code'    => 422,
                'data'    => null,
                'errors'  => [
                    'amount' => ["Cannot release {$amount}; only {$currentEncumbered} is currently encumbered."],
                ],
            ];
        }

        try {
            DB::beginTransaction();

            $before        = $budget->only(['encumbered_amount', 'spent_amount', 'total_amount']);
            $newEncumbered = bcsub($currentEncumbered, $amount, self::SCALE);

            $budget->update(['encumbered_amount' => $newEncumbered]);

            BudgetTransaction::create([
                'budget_id'      => $budget->id,
                'type'           => 'release',
                'amount'         => $amount,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'created_by'     => $actor?->id,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BudgetService::releaseEncumbrance failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to release encumbrance. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'budget_released',
            entityId:   $budget->id,
            before:     $before,
            after:      $budget->fresh()->only(['encumbered_amount', 'spent_amount', 'total_amount']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        return [
            'success' => true,
            'message' => 'Encumbrance released successfully.',
            'code'    => 200,
            'data'    => $budget->fresh(),
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // 13.6 — Record Expenditure (Invoice approved for payment)
    // -------------------------------------------------------------------------

    /**
     * Record actual expenditure when an Invoice is approved for payment.
     * The corresponding encumbrance (from the originating PO) is released
     * and the spent_amount is incremented by the invoiced amount.
     *
     * Requirements: 13.6
     *
     * @param  Budget       $budget
     * @param  string       $amount          Actual invoiced/payment amount
     * @param  string       $encumberedAmount Amount to release from encumbrance (may differ from invoiced)
     * @param  string       $referenceType   e.g. 'invoice'
     * @param  string       $referenceId     UUID of the Invoice
     * @param  User|null    $actor
     * @param  string       $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: Budget|null, errors: array|null}
     */
    public function recordExpenditure(
        Budget $budget,
        string $amount,
        string $encumberedAmount,
        string $referenceType,
        string $referenceId,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $amount          = $this->normalise($amount);
        $encumberedAmount = $this->normalise($encumberedAmount);

        if (bccomp($amount, '0.00', self::SCALE) <= 0) {
            return [
                'success' => false,
                'message' => 'Expenditure amount must be greater than zero.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['amount' => ['Expenditure amount must be greater than zero.']],
            ];
        }

        $currentEncumbered = $this->normalise($budget->encumbered_amount);

        // Clamp release to what is actually encumbered
        $releaseAmount = bccomp($encumberedAmount, $currentEncumbered, self::SCALE) > 0
            ? $currentEncumbered
            : $encumberedAmount;

        try {
            DB::beginTransaction();

            $before = $budget->only(['encumbered_amount', 'spent_amount', 'total_amount']);

            $newEncumbered = bcsub($currentEncumbered, $releaseAmount, self::SCALE);
            $newSpent      = bcadd(
                $this->normalise($budget->spent_amount),
                $amount,
                self::SCALE,
            );

            $budget->update([
                'encumbered_amount' => $newEncumbered,
                'spent_amount'      => $newSpent,
            ]);

            // Record the spend transaction
            BudgetTransaction::create([
                'budget_id'      => $budget->id,
                'type'           => 'spend',
                'amount'         => $amount,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'created_by'     => $actor?->id,
            ]);

            // If encumberedAmount > actualAmount, record leftover release
            if (bccomp($releaseAmount, $amount, self::SCALE) > 0) {
                $leftover = bcsub($releaseAmount, $amount, self::SCALE);

                BudgetTransaction::create([
                    'budget_id'      => $budget->id,
                    'type'           => 'release',
                    'amount'         => $leftover,
                    'reference_type' => $referenceType,
                    'reference_id'   => $referenceId,
                    'created_by'     => $actor?->id,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BudgetService::recordExpenditure failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to record expenditure. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'budget_expenditure_recorded',
            entityId:   $budget->id,
            before:     $before,
            after:      $budget->fresh()->only(['encumbered_amount', 'spent_amount', 'total_amount']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        // Check and dispatch threshold notifications
        $this->checkAndDispatchThresholdNotifications($budget->fresh(), $actor?->tenant_id ?? app('tenant')->id);

        return [
            'success' => true,
            'message' => 'Expenditure recorded successfully.',
            'code'    => 200,
            'data'    => $budget->fresh(),
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // 13.8 — Transfer Budget Between Departments
    // -------------------------------------------------------------------------

    /**
     * Transfer a monetary amount from one department's budget to another,
     * within the same fiscal year and tenant.
     *
     * Both budgets must belong to the same tenant (enforced by the global
     * tenant scope). The source budget cannot be reduced below its already
     * committed amount (encumbered + spent).
     *
     * Requirements: 13.8
     *
     * @param  Budget       $sourceBudget
     * @param  Budget       $destinationBudget
     * @param  string       $amount
     * @param  User|null    $actor
     * @param  string       $ipAddress
     * @param  string|null  $requestId
     *
     * @return array{success: bool, message: string, code: int, data: array|null, errors: array|null}
     */
    public function transferBudget(
        Budget $sourceBudget,
        Budget $destinationBudget,
        string $amount,
        ?User $actor = null,
        string $ipAddress = '0.0.0.0',
        ?string $requestId = null,
    ): array {
        $amount = $this->normalise($amount);

        if (bccomp($amount, '0.00', self::SCALE) <= 0) {
            return [
                'success' => false,
                'message' => 'Transfer amount must be greater than zero.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['amount' => ['Transfer amount must be greater than zero.']],
            ];
        }

        if ($sourceBudget->id === $destinationBudget->id) {
            return [
                'success' => false,
                'message' => 'Source and destination budgets must be different.',
                'code'    => 422,
                'data'    => null,
                'errors'  => ['destination_budget_id' => ['Source and destination budgets must be different.']],
            ];
        }

        // Source must have enough available (uncommitted) funds
        $sourceAvailable = $this->computeAvailable($sourceBudget);

        if (bccomp($amount, $sourceAvailable, self::SCALE) > 0) {
            $shortfall = bcsub($amount, $sourceAvailable, self::SCALE);

            return [
                'success' => false,
                'message' => "Transfer amount {$amount} exceeds available balance {$sourceAvailable} in source budget (shortfall: {$shortfall}).",
                'code'    => 422,
                'data'    => null,
                'errors'  => [
                    'amount' => [
                        "Transfer amount {$amount} exceeds available balance {$sourceAvailable} in source budget (shortfall: {$shortfall}).",
                    ],
                ],
            ];
        }

        // Generate a shared reference UUID for both transaction legs
        $referenceId = (string) \Illuminate\Support\Str::uuid();

        try {
            DB::beginTransaction();

            $sourceBefore      = $sourceBudget->only(['total_amount', 'encumbered_amount', 'spent_amount']);
            $destinationBefore = $destinationBudget->only(['total_amount', 'encumbered_amount', 'spent_amount']);

            // Reduce source total_amount
            $sourceBudget->update([
                'total_amount' => bcsub(
                    $this->normalise($sourceBudget->total_amount),
                    $amount,
                    self::SCALE,
                ),
            ]);

            // Increase destination total_amount
            $destinationBudget->update([
                'total_amount' => bcadd(
                    $this->normalise($destinationBudget->total_amount),
                    $amount,
                    self::SCALE,
                ),
            ]);

            // Record transfer_out on source
            BudgetTransaction::create([
                'budget_id'      => $sourceBudget->id,
                'type'           => 'transfer_out',
                'amount'         => $amount,
                'reference_type' => 'budget_transfer',
                'reference_id'   => $referenceId,
                'created_by'     => $actor?->id,
            ]);

            // Record transfer_in on destination
            BudgetTransaction::create([
                'budget_id'      => $destinationBudget->id,
                'type'           => 'transfer_in',
                'amount'         => $amount,
                'reference_type' => 'budget_transfer',
                'reference_id'   => $referenceId,
                'created_by'     => $actor?->id,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BudgetService::transferBudget failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Failed to transfer budget. Please try again.',
                'code'    => 500,
                'data'    => null,
                'errors'  => null,
            ];
        }

        $tenantId = $actor?->tenant_id ?? app('tenant')->id;

        // Audit log both legs of the transfer
        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'budget_transfer_out',
            entityId:   $sourceBudget->id,
            before:     $sourceBefore,
            after:      $sourceBudget->fresh()->only(['total_amount', 'encumbered_amount', 'spent_amount']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        $this->dispatchAuditLog(
            actor:      $actor,
            actionType: 'budget_transfer_in',
            entityId:   $destinationBudget->id,
            before:     $destinationBefore,
            after:      $destinationBudget->fresh()->only(['total_amount', 'encumbered_amount', 'spent_amount']),
            ipAddress:  $ipAddress,
            requestId:  $requestId,
        );

        // Check threshold for destination (it now has more to spend)
        $this->checkAndDispatchThresholdNotifications($destinationBudget->fresh(), $tenantId);

        return [
            'success' => true,
            'message' => 'Budget transferred successfully.',
            'code'    => 200,
            'data'    => [
                'source_budget'      => $sourceBudget->fresh()->load('department'),
                'destination_budget' => $destinationBudget->fresh()->load('department'),
                'transferred_amount' => $amount,
                'reference_id'       => $referenceId,
            ],
            'errors'  => null,
        ];
    }

    // -------------------------------------------------------------------------
    // 13.10 — Real-time Utilization Report
    // -------------------------------------------------------------------------

    /**
     * Return a real-time budget utilization summary for all departments
     * (or a single department) for a given fiscal year within the active tenant.
     *
     * Requirements: 13.10
     *
     * @param  int         $fiscalYear
     * @param  string|null $departmentId  Optionally filter to a single department
     *
     * @return Collection  Each item contains the budget plus computed fields:
     *                     available_amount, utilization_percent, committed_percent
     */
    public function getUtilizationReport(int $fiscalYear, ?string $departmentId = null): Collection
    {
        $query = Budget::with(['department'])
            ->where('fiscal_year', $fiscalYear);

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $budgets = $query->get();

        return $budgets->map(function (Budget $budget) {
            $total      = $this->normalise($budget->total_amount);
            $encumbered = $this->normalise($budget->encumbered_amount);
            $spent      = $this->normalise($budget->spent_amount);
            $committed  = bcadd($encumbered, $spent, self::SCALE);
            $available  = $this->computeAvailable($budget);

            // utilization % = (encumbered + spent) / total * 100
            $utilizationPercent = bccomp($total, '0.00', self::SCALE) > 0
                ? bcdiv(bcmul($committed, '100', self::SCALE + 4), $total, self::SCALE)
                : '0.00';

            $budget->setAttribute('available_amount', $available);
            $budget->setAttribute('committed_amount', $committed);
            $budget->setAttribute('utilization_percent', $utilizationPercent);

            return $budget;
        });
    }

    /**
     * Retrieve a paginated list of budgets with utilization data.
     *
     * Requirements: 13.10
     *
     * @param  array{
     *     fiscal_year?: int,
     *     department_id?: string|null,
     *     per_page?: int,
     * }  $filters
     */
    public function paginatedUtilizationReport(array $filters = []): LengthAwarePaginator
    {
        $fiscalYear = $filters['fiscal_year'] ?? now()->year;
        $query      = Budget::with(['department'])
            ->where('fiscal_year', $fiscalYear);

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    // -------------------------------------------------------------------------
    // Helper — resolve budget for a department/fiscal year
    // -------------------------------------------------------------------------

    /**
     * Retrieve the active budget for a department in the given fiscal year,
     * scoped to the current tenant via the global scope.
     */
    public function getActiveBudgetForDepartment(string $departmentId, int $fiscalYear): ?Budget
    {
        return Budget::where('department_id', $departmentId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }

    // -------------------------------------------------------------------------
    // Threshold Notification Dispatch
    // -------------------------------------------------------------------------

    /**
     * Check the current budget utilization and dispatch a queued notification
     * job if the 75 % or 90 % threshold has just been crossed.
     *
     * We check against both thresholds on every write so that a single large
     * transaction can trigger both alerts if necessary.
     *
     * Requirements: 13.7
     *
     * @param  Budget  $budget     Freshly loaded budget after write
     * @param  string  $tenantId
     */
    private function checkAndDispatchThresholdNotifications(Budget $budget, string $tenantId): void
    {
        $total     = $this->normalise($budget->total_amount);
        $committed = bcadd(
            $this->normalise($budget->encumbered_amount),
            $this->normalise($budget->spent_amount),
            self::SCALE,
        );

        if (bccomp($total, '0.00', self::SCALE) <= 0) {
            return;
        }

        // utilization_percent = committed / total * 100 (4 extra decimal places for precision)
        $utilizationPercent = bcdiv(
            bcmul($committed, '100', self::SCALE + 4),
            $total,
            self::SCALE,
        );

        foreach ([75, 90] as $threshold) {
            $thresholdDecimal = (string) $threshold . '.00';

            if (bccomp($utilizationPercent, $thresholdDecimal, self::SCALE) >= 0) {
                SendBudgetThresholdNotificationJob::dispatch(
                    budgetId:           $budget->id,
                    tenantId:           $tenantId,
                    thresholdPercent:   $threshold,
                    usedAmount:         $committed,
                    totalAmount:        $total,
                    utilizationPercent: $utilizationPercent,
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // BCMath Helpers
    // -------------------------------------------------------------------------

    /**
     * Compute the available balance for a budget using BCMath.
     *
     * available = total_amount − encumbered_amount − spent_amount
     *
     * Returns '0.00' if the result would be negative (over-allocated state).
     */
    private function computeAvailable(Budget $budget): string
    {
        $total      = $this->normalise($budget->total_amount);
        $encumbered = $this->normalise($budget->encumbered_amount);
        $spent      = $this->normalise($budget->spent_amount);

        $committed = bcadd($encumbered, $spent, self::SCALE);
        $available = bcsub($total, $committed, self::SCALE);

        // Clamp to zero — cannot be negative in normal operation
        return bccomp($available, '0.00', self::SCALE) >= 0 ? $available : '0.00';
    }

    /**
     * Normalise a monetary value to a string with exactly SCALE decimal places,
     * stripping any trailing whitespace or unexpected formatting.
     *
     * Accepts string, int, or float inputs. Float inputs are cast to string
     * via number_format to avoid IEEE-754 rounding artefacts before BCMath
     * processing.
     *
     * @param  string|int|float  $value
     * @return string
     */
    private function normalise(string|int|float $value): string
    {
        if (is_float($value)) {
            // Convert float to a precise string representation first
            $value = number_format($value, self::SCALE + 4, '.', '');
        }

        $value = trim((string) $value);

        // Ensure exactly SCALE decimal places
        return bcadd($value, '0', self::SCALE);
    }

    // -------------------------------------------------------------------------
    // Audit Log
    // -------------------------------------------------------------------------

    /**
     * Dispatch an async audit log entry on the 'default' queue.
     */
    private function dispatchAuditLog(
        ?User $actor,
        string $actionType,
        string $entityId,
        ?array $before,
        ?array $after,
        string $ipAddress,
        ?string $requestId,
    ): void {
        try {
            WriteAuditLogJob::dispatch(
                tenantId:   $actor?->tenant_id ?? (app()->has('tenant') ? app('tenant')->id : null),
                userId:     $actor?->id,
                userRole:   $actor?->getRoleNames()->first(),
                actionType: $actionType,
                entityType: 'budget',
                entityId:   $entityId,
                before:     $before,
                after:      $after,
                ipAddress:  $ipAddress,
                requestId:  $requestId,
            );
        } catch (\Throwable $e) {
            Log::error('BudgetService: failed to dispatch audit log', [
                'error'       => $e->getMessage(),
                'action_type' => $actionType,
                'entity_id'   => $entityId,
            ]);
        }
    }
}
