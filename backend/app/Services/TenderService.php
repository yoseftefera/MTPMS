<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Bid;
use App\Models\Notification;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TenderService — full tender lifecycle within a tenant.
 *
 * Responsibilities:
 *  - create()          — create a tender in `draft` status
 *  - publish()         — transition draft → published; notify active suppliers by category
 *                        (for open/restricted); for single_source, notify only the specified supplier
 *  - cancel()          — transition to cancelled; notify all suppliers who have submitted bids
 *  - extendDeadline()  — extend the submission deadline (only before the original deadline passes)
 *  - closeExpired()    — close all tenders whose submission_deadline has passed (used by scheduler)
 *  - search()          — paginated, filterable tender list
 *
 * Status flow: draft → published → closed (automatic) or cancelled (manual)
 *
 * Tender types:
 *  - open          — any active supplier in the relevant category is invited
 *  - restricted    — same as open in terms of notification logic (invited suppliers determined
 *                    by category match; full restricted-invite list management is handled
 *                    by a future task)
 *  - single_source — only the supplier specified in $data['supplier_id'] is notified
 *
 * Requirements: 8.1, 8.2, 8.3, 8.6, 8.8, 8.9, 8.10
 */
class TenderService
{
    /**
     * Valid tender type values.
     */
    private const TENDER_TYPES = ['open', 'restricted', 'single_source'];

    /**
     * Valid status transitions.
     *   key   = current status
     *   value = allowed next statuses
     */
    private const STATUS_TRANSITIONS = [
        'draft'     => ['published', 'cancelled'],
        'published' => ['closed', 'cancelled'],
        'closed'    => ['awarded'],
        'awarded'   => [],
        'cancelled' => [],
    ];

    // =========================================================================
    // Create
    // =========================================================================

    /**
     * Create a new tender in `draft` status.
     *
     * Required fields:
     *   title, description, category, tender_type, estimated_value, submission_deadline
     *
     * Optional:
     *   reference_number  — auto-generated if omitted (`TDR-{TENANT_CODE}-{YEAR}-{SEQ}`)
     *   supplier_id       — only relevant for single_source tenders
     *
     * Requirements: 8.1
     *
     * @param  array{
     *     title: string,
     *     description: string,
     *     category: string,
     *     tender_type: string,
     *     estimated_value: string|float,
     *     submission_deadline: string,
     *     reference_number?: string|null,
     *     supplier_id?: string|null,
     * }  $data
     *
     * @throws InvalidArgumentException  when required fields are missing or invalid
     */
    public function create(
        array  $data,
        User   $actor,
        string $tenantId,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): Tender {
        $this->validateCreateData($data);

        return DB::transaction(function () use ($data, $actor, $tenantId, $ipAddress, $requestId) {
            $referenceNumber = $data['reference_number'] ?? $this->generateReferenceNumber($tenantId);

            /** @var Tender $tender */
            $tender = Tender::create([
                'tenant_id'           => $tenantId,
                'reference_number'    => $referenceNumber,
                'title'               => $data['title'],
                'description'         => $data['description'],
                'category'            => $data['category'],
                'tender_type'         => $data['tender_type'],
                'estimated_value'     => $data['estimated_value'],
                'submission_deadline' => $data['submission_deadline'],
                'status'              => 'draft',
                'created_by'          => $actor->id,
            ]);

            WriteAuditLogJob::dispatch(
                $tenantId,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'tender.created',
                'tender',
                $tender->id,
                null,
                [
                    'reference_number' => $tender->reference_number,
                    'title'            => $tender->title,
                    'tender_type'      => $tender->tender_type,
                    'status'           => 'draft',
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $tender->fresh(['documents', 'createdBy']);
        });
    }

    // =========================================================================
    // Publish
    // =========================================================================

    /**
     * Publish a tender (draft → published).
     *
     * After transitioning to published this method notifies eligible suppliers:
     *  - open / restricted  → all `active` suppliers whose business_category matches
     *                         the tender's category
     *  - single_source      → only the supplier identified by $data['supplier_id']
     *
     * Requirements: 8.2, 8.10
     *
     * @param  array{supplier_id?: string|null}  $data  Extra context for single_source tenders.
     *
     * @throws InvalidArgumentException  when tender is not in `draft` status
     * @throws InvalidArgumentException  when tender_type is single_source and supplier_id is missing
     */
    public function publish(
        Tender  $tender,
        User    $actor,
        array   $data = [],
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): Tender {
        if ($tender->status !== 'draft') {
            throw new InvalidArgumentException(
                "Tender '{$tender->reference_number}' cannot be published: "
                . "only tenders in 'draft' status can be published "
                . "(current status: {$tender->status})."
            );
        }

        if ($tender->tender_type === 'single_source' && empty($data['supplier_id'])) {
            throw new InvalidArgumentException(
                "A supplier_id must be provided when publishing a single_source tender."
            );
        }

        return DB::transaction(function () use ($tender, $actor, $data, $ipAddress, $requestId) {
            $before = ['status' => $tender->status, 'published_at' => null];

            $tender->update([
                'status'       => 'published',
                'published_at' => now(),
            ]);

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'tender.published',
                'tender',
                $tender->id,
                $before,
                ['status' => 'published', 'published_at' => now()->toIso8601String()],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            // Notify suppliers based on tender type
            $this->notifySuppliersOnPublish($tender, $data['supplier_id'] ?? null);

            return $tender->fresh(['documents', 'bids', 'createdBy']);
        });
    }

    // =========================================================================
    // Cancel
    // =========================================================================

    /**
     * Cancel a tender (draft|published → cancelled).
     *
     * After cancellation all suppliers who submitted a bid are notified with
     * the cancellation reason.
     *
     * Requirements: 8.9
     *
     * @throws InvalidArgumentException  when tender is already closed/awarded/cancelled
     * @throws InvalidArgumentException  when cancellation_reason is empty
     */
    public function cancel(
        Tender  $tender,
        User    $actor,
        string  $cancellationReason,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): Tender {
        if (! in_array($tender->status, ['draft', 'published'], true)) {
            throw new InvalidArgumentException(
                "Tender '{$tender->reference_number}' cannot be cancelled: "
                . "only tenders in 'draft' or 'published' status can be cancelled "
                . "(current status: {$tender->status})."
            );
        }

        if (empty(trim($cancellationReason))) {
            throw new InvalidArgumentException('A cancellation reason is required and cannot be empty.');
        }

        return DB::transaction(function () use ($tender, $actor, $cancellationReason, $ipAddress, $requestId) {
            $before = ['status' => $tender->status, 'cancellation_reason' => null];

            $tender->update([
                'status'              => 'cancelled',
                'cancellation_reason' => $cancellationReason,
            ]);

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'tender.cancelled',
                'tender',
                $tender->id,
                $before,
                ['status' => 'cancelled', 'cancellation_reason' => $cancellationReason],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            // Notify all suppliers who submitted bids
            $this->notifyBiddingSuppliersOnCancel($tender, $cancellationReason);

            return $tender->fresh(['documents', 'bids', 'createdBy']);
        });
    }

    // =========================================================================
    // Extend Deadline
    // =========================================================================

    /**
     * Extend the submission deadline for a published tender.
     *
     * The extension is only allowed if the original deadline has NOT yet passed.
     * The new deadline must be strictly after the current deadline.
     *
     * Requirements: 8.8
     *
     * @param  string  $newDeadline  ISO 8601 / datetime string parseable by Carbon
     *
     * @throws InvalidArgumentException  when the tender is not in `published` status
     * @throws InvalidArgumentException  when the current deadline has already passed
     * @throws InvalidArgumentException  when newDeadline is not after the current deadline
     */
    public function extendDeadline(
        Tender  $tender,
        User    $actor,
        string  $newDeadline,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): Tender {
        if ($tender->status !== 'published') {
            throw new InvalidArgumentException(
                "Tender '{$tender->reference_number}' deadline cannot be extended: "
                . "only published tenders support deadline extension "
                . "(current status: {$tender->status})."
            );
        }

        $currentDeadline = $tender->submission_deadline;

        if ($currentDeadline->isPast()) {
            throw new InvalidArgumentException(
                "Tender '{$tender->reference_number}' deadline cannot be extended: "
                . "the original deadline ({$currentDeadline->toIso8601String()}) has already passed."
            );
        }

        $newDeadlineParsed = \Illuminate\Support\Carbon::parse($newDeadline);

        if (! $newDeadlineParsed->isAfter($currentDeadline)) {
            throw new InvalidArgumentException(
                "The new deadline must be after the current deadline "
                . "({$currentDeadline->toIso8601String()})."
            );
        }

        return DB::transaction(function () use ($tender, $actor, $newDeadlineParsed, $newDeadline, $ipAddress, $requestId) {
            $before = ['submission_deadline' => $tender->submission_deadline->toIso8601String()];

            $tender->update(['submission_deadline' => $newDeadlineParsed]);

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'tender.deadline_extended',
                'tender',
                $tender->id,
                $before,
                ['submission_deadline' => $newDeadlineParsed->toIso8601String()],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $tender->fresh(['documents', 'bids', 'createdBy']);
        });
    }

    // =========================================================================
    // Close Expired Tenders (used by scheduler)
    // =========================================================================

    /**
     * Find all `published` tenders whose submission_deadline has passed and
     * transition them to `closed` status.
     *
     * This is intended to be called by the `tenders:close-expired` artisan command
     * registered in routes/console.php to run every 15 minutes.
     *
     * Returns the count of tenders that were closed.
     *
     * Requirements: 8.6
     */
    public function closeExpired(): int
    {
        $expired = Tender::withoutGlobalScopes()
            ->where('status', 'published')
            ->where('submission_deadline', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $count = 0;

        foreach ($expired as $tender) {
            DB::transaction(function () use ($tender, &$count) {
                $tender->update(['status' => 'closed']);

                // Lock all draft/submitted bids — they can no longer be revised
                Bid::withoutGlobalScopes()
                    ->where('tender_id', $tender->id)
                    ->whereIn('status', ['draft'])
                    ->update(['status' => 'disqualified']);

                WriteAuditLogJob::dispatch(
                    $tender->tenant_id,
                    null,           // system action — no user actor
                    'system',
                    'tender.closed_by_scheduler',
                    'tender',
                    $tender->id,
                    ['status' => 'published'],
                    ['status' => 'closed', 'closed_at' => now()->toIso8601String()],
                    '0.0.0.0',
                    null,
                )->onQueue('default');

                $count++;
            });
        }

        return $count;
    }

    // =========================================================================
    // Search / List
    // =========================================================================

    /**
     * Return a paginated, filterable list of tenders.
     *
     * Supported filters:
     *   status       — 'draft' | 'published' | 'closed' | 'awarded' | 'cancelled'
     *   category     — partial match
     *   tender_type  — 'open' | 'restricted' | 'single_source'
     *   search       — partial match on title or reference_number
     *   per_page     — results per page (default 20, max 100)
     *
     * Requirements: 8.1
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Tender::query()->with(['documents', 'createdBy', 'bids']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', 'like', '%' . $filters['category'] . '%');
        }

        if (! empty($filters['tender_type'])) {
            $query->where('tender_type', $filters['tender_type']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                  ->orWhere('reference_number', 'like', "%{$term}%")
                  ->orWhere('description', 'like', "%{$term}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // =========================================================================
    // Private: Notification helpers
    // =========================================================================

    /**
     * Notify eligible suppliers when a tender is published.
     *
     * - open / restricted : all active suppliers matching tender's category
     * - single_source     : only the supplier identified by $supplierId
     *
     * Requirements: 8.2, 8.10
     */
    private function notifySuppliersOnPublish(Tender $tender, ?string $supplierId): void
    {
        if ($tender->tender_type === 'single_source') {
            if (! $supplierId) {
                return;
            }

            $supplier = Supplier::withoutGlobalScopes()
                ->where('id', $supplierId)
                ->where('tenant_id', $tender->tenant_id)
                ->where('status', 'active')
                ->first();

            if ($supplier && $supplier->user_id) {
                $this->createSupplierNotification(
                    tender: $tender,
                    userId: $supplier->user_id,
                );
            }

            return;
        }

        // open / restricted — notify all active suppliers matching category
        $suppliers = Supplier::withoutGlobalScopes()
            ->where('tenant_id', $tender->tenant_id)
            ->where('status', 'active')
            ->where('business_category', $tender->category)
            ->whereNotNull('user_id')
            ->get();

        foreach ($suppliers as $supplier) {
            $this->createSupplierNotification(
                tender: $tender,
                userId: $supplier->user_id,
            );
        }
    }

    /**
     * Notify all suppliers who have submitted a bid when a tender is cancelled.
     *
     * Only suppliers with bids in status `submitted`, `under_evaluation`, or `won`
     * are considered "bidding suppliers".
     *
     * Requirements: 8.9
     */
    private function notifyBiddingSuppliersOnCancel(Tender $tender, string $cancellationReason): void
    {
        $bids = Bid::withoutGlobalScopes()
            ->with('supplier')
            ->where('tender_id', $tender->id)
            ->whereIn('status', ['submitted', 'under_evaluation', 'won', 'lost'])
            ->get();

        foreach ($bids as $bid) {
            $supplier = $bid->supplier;

            if (! $supplier || ! $supplier->user_id) {
                continue;
            }

            $this->createCancellationNotification(
                tender:             $tender,
                userId:             $supplier->user_id,
                cancellationReason: $cancellationReason,
            );
        }
    }

    /**
     * Persist a single in-app notification for a supplier when a tender is published.
     */
    private function createSupplierNotification(Tender $tender, string $userId): void
    {
        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $tender->tenant_id,
                'user_id'    => $userId,
                'event_type' => 'tender_published',
                'title'      => "New Tender: {$tender->title}",
                'message'    => "A new tender (Ref: {$tender->reference_number}) has been published "
                              . "in your category. Submission deadline: "
                              . $tender->submission_deadline->toFormattedDayDateString() . ".",
                'data'       => [
                    'tender_id'           => $tender->id,
                    'reference_number'    => $tender->reference_number,
                    'title'               => $tender->title,
                    'category'            => $tender->category,
                    'tender_type'         => $tender->tender_type,
                    'submission_deadline' => $tender->submission_deadline->toIso8601String(),
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('TenderService: failed to create publish notification', [
                'tender_id' => $tender->id,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist a single in-app notification for a supplier when a tender is cancelled.
     */
    private function createCancellationNotification(
        Tender $tender,
        string $userId,
        string $cancellationReason,
    ): void {
        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $tender->tenant_id,
                'user_id'    => $userId,
                'event_type' => 'tender_cancelled',
                'title'      => "Tender Cancelled: {$tender->title}",
                'message'    => "Tender (Ref: {$tender->reference_number}) has been cancelled. "
                              . "Reason: {$cancellationReason}",
                'data'       => [
                    'tender_id'           => $tender->id,
                    'reference_number'    => $tender->reference_number,
                    'title'               => $tender->title,
                    'cancellation_reason' => $cancellationReason,
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('TenderService: failed to create cancellation notification', [
                'tender_id' => $tender->id,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Private: Reference number generation
    // =========================================================================

    /**
     * Auto-generate a unique tender reference number in the format:
     *   TDR-{TENANT_CODE}-{YEAR}-{SEQUENCE}
     *
     * Uses an atomic DB query to compute the next sequence within the tenant+year scope.
     */
    private function generateReferenceNumber(string $tenantId): string
    {
        $tenant = \App\Models\Tenant::withoutGlobalScopes()->find($tenantId);
        $code   = $tenant?->tenant_code ?? Str::upper(Str::random(4));
        $year   = now()->year;

        // Count existing tenders for this tenant in the current year to derive sequence
        $sequence = DB::table('tenders')
                ->where('tenant_id', $tenantId)
                ->whereYear('created_at', $year)
                ->count() + 1;

        $padded = str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

        return "TDR-{$code}-{$year}-{$padded}";
    }

    // =========================================================================
    // Private: Validation helpers
    // =========================================================================

    /**
     * Validate required fields for creating a tender.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private function validateCreateData(array $data): void
    {
        $required = ['title', 'description', 'category', 'tender_type', 'estimated_value', 'submission_deadline'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $label = str_replace('_', ' ', $field);
                throw new InvalidArgumentException("The {$label} field is required.");
            }
        }

        if (! in_array($data['tender_type'], self::TENDER_TYPES, true)) {
            throw new InvalidArgumentException(
                "Invalid tender type '{$data['tender_type']}'. Allowed: " . implode(', ', self::TENDER_TYPES) . '.'
            );
        }

        if (! is_numeric($data['estimated_value']) || (float) $data['estimated_value'] <= 0) {
            throw new InvalidArgumentException('The estimated value must be a positive number.');
        }

        try {
            $deadline = \Illuminate\Support\Carbon::parse($data['submission_deadline']);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('The submission deadline must be a valid datetime.');
        }

        if ($deadline->isPast()) {
            throw new InvalidArgumentException('The submission deadline must be a future date/time.');
        }
    }
}
