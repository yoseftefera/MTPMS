<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractDocument;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * ContractService — full contract lifecycle within a tenant.
 *
 * Contract number format: CON-{TENANT_CODE}-{YEAR}-{SEQUENCE}
 * Example: CON-ACME-2024-00001
 *
 * Status flow:
 *  draft → active        (activate — requires performance bond document)
 *  active → terminated   (terminate — reason required)
 *
 * Value consumption:
 *  - checkValueConsumption() : alert Procurement_Officer when spend ≥ 80% of contract value
 *
 * Requirements: 11.1, 11.2, 11.5, 11.6, 11.7, 11.8, 11.9, 11.10
 */
class ContractService
{
    /**
     * BCMath decimal scale — matches DECIMAL(15,2) database columns.
     */
    private const SCALE = 2;

    /**
     * Percentage threshold at which a value-consumption alert is sent.
     */
    private const CONSUMPTION_ALERT_THRESHOLD = 80;

    // =========================================================================
    // Contract Number Generation
    // =========================================================================

    /**
     * Generate the next sequential contract number for the given tenant and year.
     *
     * Format: CON-{TENANT_CODE}-{YEAR}-{SEQUENCE}  (SEQUENCE zero-padded to 5 digits)
     * Example: CON-ACME-2024-00001
     *
     * A pessimistic lock (SELECT … FOR UPDATE) is used inside a database
     * transaction to prevent duplicate sequences under concurrent submissions.
     *
     * Requirements: 11.1
     */
    public function generateContractNumber(string $tenantCode, int $year = 0): string
    {
        if ($year === 0) {
            $year = now()->year;
        }

        $tenantCode = strtoupper($tenantCode);

        $sequence = DB::transaction(function () use ($tenantCode, $year) {
            $tenant = Tenant::withoutGlobalScopes()
                ->where('tenant_code', $tenantCode)
                ->first();

            if (! $tenant) {
                return 1;
            }

            $count = DB::table('contracts')
                ->where('tenant_id', $tenant->id)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();

            return $count + 1;
        });

        return sprintf('CON-%s-%d-%05d', $tenantCode, $year, $sequence);
    }

    // =========================================================================
    // Create
    // =========================================================================

    /**
     * Create a new Contract in `draft` status linked to a PO or Tender.
     *
     * Required fields:
     *   supplier_id, title, scope, total_value, start_date, end_date
     *
     * Optional (at least one should be supplied for linkage):
     *   purchase_order_id, tender_id, bid_id, currency, payment_terms
     *
     * Requirements: 11.1, 11.2
     *
     * @param  array{
     *     supplier_id: string,
     *     title: string,
     *     scope: string,
     *     total_value: string|int|float,
     *     start_date: string,
     *     end_date: string,
     *     purchase_order_id?: string|null,
     *     tender_id?: string|null,
     *     currency?: string,
     *     payment_terms?: string|null,
     * }  $data
     *
     * @throws InvalidArgumentException  when required fields are missing
     */
    public function create(array $data, User $actor): Contract
    {
        $this->validateCreateData($data);

        return DB::transaction(function () use ($data, $actor) {
            $tenant     = $actor->tenant ?? app('tenant');
            $tenantId   = $actor->tenant_id ?? $tenant->id;
            $tenantCode = strtoupper($tenant->tenant_code);

            $contractNumber = $this->generateContractNumber($tenantCode);

            /** @var Contract $contract */
            $contract = Contract::create([
                'contract_number'   => $contractNumber,
                'tenant_id'         => $tenantId,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'tender_id'         => $data['tender_id'] ?? null,
                'supplier_id'       => $data['supplier_id'],
                'title'             => $data['title'],
                'scope'             => $data['scope'],
                'total_value'       => $this->normalise($data['total_value']),
                'consumed_value'    => '0.00',
                'currency'          => $data['currency'] ?? 'USD',
                'start_date'        => $data['start_date'],
                'end_date'          => $data['end_date'],
                'payment_terms'     => $data['payment_terms'] ?? null,
                'status'            => 'draft',
                'created_by'        => $actor->id,
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $tenantId,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'contract.created',
                entityType: 'contract',
                entityId:   $contract->id,
                before:     null,
                after:      [
                    'contract_number' => $contract->contract_number,
                    'status'          => 'draft',
                    'total_value'     => $contract->total_value,
                    'supplier_id'     => $data['supplier_id'],
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');

            return $contract->load(['supplier', 'purchaseOrder', 'tender', 'createdBy']);
        });
    }

    // =========================================================================
    // Activate (draft → active)
    // =========================================================================

    /**
     * Activate a Contract (draft → active).
     *
     * Blocked if no ContractDocument with document_type = 'performance_bond' exists.
     *
     * Requirements: 11.7, 11.8
     *
     * @throws InvalidArgumentException  when the contract is not in `draft` status
     * @throws InvalidArgumentException  when no performance bond document is uploaded
     */
    public function activate(Contract $contract, User $actor): void
    {
        if ($contract->status !== 'draft') {
            throw new InvalidArgumentException(
                "Contract {$contract->contract_number} cannot be activated: "
                . "only draft contracts can be activated (current status: {$contract->status})."
            );
        }

        $hasPerformanceBond = ContractDocument::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->where('document_type', 'performance_bond')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasPerformanceBond) {
            throw new InvalidArgumentException(
                'Cannot activate contract: a performance bond document is required.'
            );
        }

        DB::transaction(function () use ($contract, $actor) {
            $before = ['status' => $contract->status];

            $contract->update(['status' => 'active']);

            WriteAuditLogJob::dispatch(
                tenantId:   $contract->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'contract.activated',
                entityType: 'contract',
                entityId:   $contract->id,
                before:     $before,
                after:      ['status' => 'active'],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // Amend
    // =========================================================================

    /**
     * Amend a Contract with a documented reason.
     *
     * Creates a ContractAmendment record with version number, reason, and
     * before/after snapshot; then updates the contract fields.
     *
     * Amendable fields: title, scope, total_value, end_date, payment_terms
     *
     * Requirements: 11.5, 11.6
     *
     * @param  array{
     *     title?: string,
     *     scope?: string,
     *     total_value?: string|int|float,
     *     end_date?: string,
     *     payment_terms?: string|null,
     * }  $changes
     *
     * @throws InvalidArgumentException  when the contract is in a non-amendable status
     * @throws InvalidArgumentException  when reason is empty
     */
    public function amend(Contract $contract, array $changes, string $reason, User $actor): void
    {
        $nonAmendableStatuses = ['terminated'];

        if (in_array($contract->status, $nonAmendableStatuses, true)) {
            throw new InvalidArgumentException(
                "Contract {$contract->contract_number} cannot be amended: "
                . "amendments are not allowed when status is '{$contract->status}'."
            );
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('An amendment reason is required and cannot be empty.');
        }

        DB::transaction(function () use ($contract, $changes, $reason, $actor) {
            // Capture snapshot of fields that may change
            $before = $contract->only([
                'title', 'scope', 'total_value', 'end_date', 'payment_terms',
            ]);

            // Build update payload
            $updateData = [];

            if (isset($changes['title'])) {
                $updateData['title'] = $changes['title'];
            }

            if (isset($changes['scope'])) {
                $updateData['scope'] = $changes['scope'];
            }

            if (isset($changes['total_value'])) {
                $updateData['total_value'] = $this->normalise($changes['total_value']);
            }

            if (isset($changes['end_date'])) {
                $updateData['end_date'] = $changes['end_date'];
            }

            if (array_key_exists('payment_terms', $changes)) {
                $updateData['payment_terms'] = $changes['payment_terms'];
            }

            if (! empty($updateData)) {
                $contract->update($updateData);
            }

            $after = $contract->fresh()->only([
                'title', 'scope', 'total_value', 'end_date', 'payment_terms',
            ]);

            // Determine next amendment version number
            $amendmentNumber = ContractAmendment::withoutGlobalScopes()
                ->where('contract_id', $contract->id)
                ->count() + 1;

            ContractAmendment::create([
                'tenant_id'        => $contract->tenant_id,
                'contract_id'      => $contract->id,
                'amendment_number' => $amendmentNumber,
                'reason'           => $reason,
                'changes'          => [
                    'before' => $before,
                    'after'  => $after,
                ],
                'amended_by'       => $actor->id,
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $contract->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'contract.amended',
                entityType: 'contract',
                entityId:   $contract->id,
                before:     $before,
                after:      array_merge($after, ['amendment_reason' => $reason, 'amendment_number' => $amendmentNumber]),
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // Terminate (active → terminated)
    // =========================================================================

    /**
     * Terminate a Contract early (active → terminated).
     *
     * A termination reason is required. Records the reason and logs the action.
     *
     * Requirements: 11.10
     *
     * @throws InvalidArgumentException  when the contract is not in `active` status
     * @throws InvalidArgumentException  when reason is empty
     */
    public function terminate(Contract $contract, string $reason, User $actor): void
    {
        if ($contract->status !== 'active') {
            throw new InvalidArgumentException(
                "Contract {$contract->contract_number} cannot be terminated: "
                . "only active contracts can be terminated (current status: {$contract->status})."
            );
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('A termination reason is required and cannot be empty.');
        }

        DB::transaction(function () use ($contract, $reason, $actor) {
            $before = ['status' => $contract->status];

            $contract->update([
                'status'               => 'terminated',
                'termination_reason'   => $reason,
            ]);

            WriteAuditLogJob::dispatch(
                tenantId:   $contract->tenant_id,
                userId:     $actor->id,
                userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
                actionType: 'contract.terminated',
                entityType: 'contract',
                entityId:   $contract->id,
                before:     $before,
                after:      ['status' => 'terminated', 'termination_reason' => $reason],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // Value Consumption Check
    // =========================================================================

    /**
     * Check whether the contract's actual spend has reached 80% of the total
     * contract value and, if so, dispatch an in-app notification to the contract
     * creator.
     *
     * Call this method after recording an invoice payment against the contract.
     *
     * Requirements: 11.9
     */
    public function checkValueConsumption(Contract $contract): void
    {
        $totalValue    = $this->normalise($contract->total_value);
        $consumedValue = $this->normalise($contract->consumed_value);

        // Avoid division by zero
        if (bccomp($totalValue, '0.00', self::SCALE) === 0) {
            return;
        }

        // Calculate consumption percentage: (consumed / total) * 100
        $percentage = (float) bcdiv(
            bcmul($consumedValue, '100', self::SCALE + 4),
            $totalValue,
            self::SCALE + 4
        );

        if ($percentage < self::CONSUMPTION_ALERT_THRESHOLD) {
            return;
        }

        if (! $contract->created_by) {
            return;
        }

        $formattedPercent = number_format($percentage, 1);

        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $contract->tenant_id,
                'user_id'    => $contract->created_by,
                'event_type' => 'contract_value_consumption_alert',
                'title'      => "Contract value consumption alert: {$contract->contract_number}",
                'message'    => "Contract {$contract->contract_number} has consumed {$formattedPercent}% "
                              . "of its total value ({$contract->currency} {$contract->total_value}). "
                              . "Consider reviewing the contract terms.",
                'data'       => [
                    'contract_id'          => $contract->id,
                    'contract_number'      => $contract->contract_number,
                    'total_value'          => $contract->total_value,
                    'consumed_value'       => $contract->consumed_value,
                    'consumption_percent'  => $formattedPercent,
                    'currency'             => $contract->currency,
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('ContractService: failed to send value consumption alert', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Search / List
    // =========================================================================

    /**
     * Return a paginated list of contracts within the active tenant scope.
     *
     * Supported filters:
     *   status       — filter by contract status
     *   supplier_id  — filter by supplier UUID
     *   date_from    — filter created_at >= date (Y-m-d)
     *   date_to      — filter created_at <= date (Y-m-d)
     *
     * Requirements: 11.1
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Contract::with(['supplier', 'purchaseOrder', 'tender', 'createdBy', 'amendments']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // =========================================================================
    // Upload Document
    // =========================================================================

    /**
     * Attach a document to a contract.
     *
     * Requirements: 11.2
     */
    public function uploadDocument(Contract $contract, array $data, User $actor): ContractDocument
    {
        $document = ContractDocument::create([
            'tenant_id'     => $contract->tenant_id,
            'contract_id'   => $contract->id,
            'document_type' => $data['document_type'],
            'file_path'     => $data['file_path'],
            'file_name'     => $data['file_name'],
            'uploaded_by'   => $actor->id,
        ]);

        WriteAuditLogJob::dispatch(
            tenantId:   $contract->tenant_id,
            userId:     $actor->id,
            userRole:   $actor->getRoleNames()->first() ?? 'procurement_officer',
            actionType: 'contract.document_uploaded',
            entityType: 'contract',
            entityId:   $contract->id,
            before:     null,
            after:      [
                'document_id'   => $document->id,
                'document_type' => $data['document_type'],
                'file_name'     => $data['file_name'],
            ],
            ipAddress:  '0.0.0.0',
            requestId:  null,
        )->onQueue('default');

        return $document;
    }

    // =========================================================================
    // Private: Data helpers
    // =========================================================================

    /**
     * Validate required fields for the create() method.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private function validateCreateData(array $data): void
    {
        $required = ['supplier_id', 'title', 'scope', 'total_value', 'start_date', 'end_date'];

        foreach ($required as $field) {
            if (empty($data[$field]) && $data[$field] !== '0') {
                $label = str_replace('_', ' ', $field);
                throw new InvalidArgumentException("The {$label} field is required.");
            }
        }

        if (bccomp($this->normalise($data['total_value']), '0.00', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Contract total value must be greater than zero.');
        }
    }

    /**
     * Normalize a monetary value to a BCMath-safe string with SCALE decimal places.
     */
    private function normalise(mixed $value): string
    {
        return bcadd((string) $value, '0.00', self::SCALE);
    }
}
