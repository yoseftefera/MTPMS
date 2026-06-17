<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Bid;
use App\Models\BidDocument;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * BidService — full bid lifecycle within a tenant.
 *
 * Responsibilities:
 *  - submit()         — create a new bid (validates deadline, one bid per supplier per tender)
 *  - revise()         — update an existing bid before the deadline
 *  - getBidsForTender() — list bids; suppliers only see their own bid
 *  - getBid()         — fetch a single bid; suppliers only see their own bid
 *  - uploadDocument() — attach a document to a bid (before deadline)
 *
 * Business rules enforced:
 *  1. Submission timestamp must be before tender's submission_deadline (Req 8.4)
 *  2. One bid per supplier per tender; revisions allowed before deadline (Req 8.5)
 *  3. Suppliers cannot see other suppliers' bids (Req 8.7)
 *  4. Only active suppliers can submit bids (Req 7.9)
 *
 * Requirements: 8.4, 8.5, 8.7
 */
class BidService
{
    // =========================================================================
    // Submit (create a new bid)
    // =========================================================================

    /**
     * Submit a new bid for the given tender.
     *
     * Rules enforced:
     *  - Tender must be in `published` status.
     *  - Current timestamp must be before the tender's submission_deadline.
     *  - Supplier must be active.
     *  - Supplier must not have an existing bid for this tender (use revise() for updates).
     *
     * @param  array{
     *     total_amount: string|float,
     *     currency?: string,
     *     delivery_days: int,
     *     technical_notes?: string|null,
     * }  $data
     *
     * @throws InvalidArgumentException  on any business rule violation
     *
     * Requirements: 8.4, 8.5
     */
    public function submit(
        Tender  $tender,
        Supplier $supplier,
        array   $data,
        User    $actor,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): Bid {
        $this->assertTenderAcceptingBids($tender);
        $this->assertSupplierActive($supplier);
        $this->assertNoDuplicateBid($tender, $supplier);

        return DB::transaction(function () use ($tender, $supplier, $data, $actor, $ipAddress, $requestId) {
            /** @var Bid $bid */
            $bid = Bid::create([
                'tenant_id'      => $tender->tenant_id,
                'tender_id'      => $tender->id,
                'supplier_id'    => $supplier->id,
                'total_amount'   => $data['total_amount'],
                'currency'       => $data['currency'] ?? 'USD',
                'delivery_days'  => $data['delivery_days'],
                'technical_notes'=> $data['technical_notes'] ?? null,
                'status'         => 'submitted',
                'submitted_at'   => now(),
            ]);

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'supplier',
                'bid.submitted',
                'bid',
                $bid->id,
                null,
                [
                    'tender_id'    => $tender->id,
                    'supplier_id'  => $supplier->id,
                    'total_amount' => $bid->total_amount,
                    'status'       => 'submitted',
                    'submitted_at' => now()->toIso8601String(),
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $bid->fresh(['tender', 'supplier', 'documents']);
        });
    }

    // =========================================================================
    // Revise (update an existing bid before the deadline)
    // =========================================================================

    /**
     * Revise an existing bid before the tender's submission deadline.
     *
     * Rules enforced:
     *  - Tender must still be accepting bids (published + before deadline).
     *  - The bid must belong to the given supplier.
     *
     * @param  array{
     *     total_amount?: string|float,
     *     currency?: string,
     *     delivery_days?: int,
     *     technical_notes?: string|null,
     * }  $data
     *
     * @throws InvalidArgumentException  when deadline has passed or bid does not belong to supplier
     *
     * Requirements: 8.4, 8.5
     */
    public function revise(
        Tender   $tender,
        Bid      $bid,
        Supplier $supplier,
        array    $data,
        User     $actor,
        ?string  $ipAddress = null,
        ?string  $requestId = null,
    ): Bid {
        $this->assertTenderAcceptingBids($tender);
        $this->assertBidBelongsToSupplier($bid, $supplier);

        return DB::transaction(function () use ($tender, $bid, $data, $actor, $ipAddress, $requestId) {
            $before = $bid->only(['total_amount', 'currency', 'delivery_days', 'technical_notes', 'status']);

            $updateData = array_filter([
                'total_amount'    => $data['total_amount'] ?? null,
                'currency'        => $data['currency'] ?? null,
                'delivery_days'   => $data['delivery_days'] ?? null,
                'technical_notes' => array_key_exists('technical_notes', $data)
                    ? $data['technical_notes']
                    : null,
            ], fn ($v) => $v !== null);

            // Keep submitted_at as the latest revision timestamp
            $updateData['submitted_at'] = now();
            $updateData['status']       = 'submitted';

            $bid->update($updateData);

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'supplier',
                'bid.revised',
                'bid',
                $bid->id,
                $before,
                $bid->fresh()->only(['total_amount', 'currency', 'delivery_days', 'technical_notes', 'status']),
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $bid->fresh(['tender', 'supplier', 'documents']);
        });
    }

    // =========================================================================
    // List bids for a tender (visibility isolation)
    // =========================================================================

    /**
     * Return a paginated list of bids for the given tender.
     *
     * Visibility rules:
     *  - Supplier role  → only their own bid is returned.
     *  - All other roles (Procurement_Officer, Tenant_Admin, Committee_Member, etc.)
     *    → all bids for the tender are returned.
     *
     * @param  string|null  $roleForIsolation  Spatie role name of the authenticated user.
     * @param  Supplier|null  $supplierForIsolation  Supplier record if the user is a Supplier.
     *
     * Requirements: 8.7
     */
    public function getBidsForTender(
        Tender   $tender,
        ?string  $roleForIsolation = null,
        ?Supplier $supplierForIsolation = null,
        int      $perPage = 20,
    ): LengthAwarePaginator {
        $query = Bid::query()
            ->with(['supplier', 'documents'])
            ->where('tender_id', $tender->id);

        // Suppliers only see their own bid
        if ($roleForIsolation === 'Supplier') {
            if ($supplierForIsolation) {
                $query->where('supplier_id', $supplierForIsolation->id);
            } else {
                // Supplier user with no linked supplier record — return empty
                $query->whereRaw('1 = 0');
            }
        }

        return $query->orderBy('submitted_at', 'asc')->paginate($perPage);
    }

    // =========================================================================
    // Show a single bid (visibility isolation)
    // =========================================================================

    /**
     * Return a single bid record, enforcing supplier visibility isolation.
     *
     * Visibility rules:
     *  - Supplier role → returns the bid only if it belongs to their supplier record;
     *    returns null otherwise (caller should return HTTP 404).
     *  - Other roles   → returns the bid unconditionally (still tenant-scoped via HasTenantScope).
     *
     * Requirements: 8.7
     */
    public function getBid(
        Bid      $bid,
        ?string  $roleForIsolation = null,
        ?Supplier $supplierForIsolation = null,
    ): ?Bid {
        if ($roleForIsolation === 'Supplier') {
            if (! $supplierForIsolation || $bid->supplier_id !== $supplierForIsolation->id) {
                return null;
            }
        }

        $bid->load(['tender', 'supplier', 'documents', 'evaluations']);

        return $bid;
    }

    // =========================================================================
    // Upload document to a bid
    // =========================================================================

    /**
     * Upload and attach a document to an existing bid.
     *
     * Rules enforced:
     *  - Tender must still be accepting bids.
     *  - The bid must belong to the given supplier.
     *
     * @throws InvalidArgumentException  when deadline has passed or bid does not belong to supplier
     *
     * Requirements: 8.4, 8.5
     */
    public function uploadDocument(
        Tender       $tender,
        Bid          $bid,
        Supplier     $supplier,
        UploadedFile $file,
        string       $documentType,
        User         $actor,
        ?string      $ipAddress = null,
        ?string      $requestId = null,
    ): BidDocument {
        $this->assertTenderAcceptingBids($tender);
        $this->assertBidBelongsToSupplier($bid, $supplier);

        $tenantId  = $tender->tenant_id;
        $uuid      = Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension());
        $storedPath = "{$tenantId}/bids/{$bid->id}/{$uuid}.{$extension}";

        Storage::disk('local')->put($storedPath, file_get_contents($file->getRealPath()));

        return DB::transaction(function () use ($bid, $documentType, $file, $storedPath, $actor, $tender, $ipAddress, $requestId) {
            $document = BidDocument::create([
                'tenant_id'     => $tender->tenant_id,
                'bid_id'        => $bid->id,
                'document_type' => $documentType,
                'file_path'     => $storedPath,
                'file_name'     => $file->getClientOriginalName(),
                'uploaded_by'   => $actor->id,
            ]);

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'supplier',
                'bid.document_uploaded',
                'bid_document',
                $document->id,
                null,
                [
                    'bid_id'        => $bid->id,
                    'document_type' => $documentType,
                    'file_name'     => $file->getClientOriginalName(),
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $document;
        });
    }

    // =========================================================================
    // Private: Guard assertions
    // =========================================================================

    /**
     * Assert that the tender is currently accepting bid submissions.
     *
     * Checks:
     *  1. Tender status must be `published`.
     *  2. Current timestamp must be strictly before the submission deadline.
     *
     * @throws InvalidArgumentException  when either condition fails
     *
     * Requirements: 8.4, 8.6
     */
    private function assertTenderAcceptingBids(Tender $tender): void
    {
        if ($tender->status !== 'published') {
            throw new InvalidArgumentException(
                "Bids cannot be submitted for tender '{$tender->reference_number}': "
                . "the tender is not currently published (status: {$tender->status})."
            );
        }

        if (now()->greaterThanOrEqualTo($tender->submission_deadline)) {
            throw new InvalidArgumentException(
                "The submission deadline for tender '{$tender->reference_number}' has passed. "
                . "Deadline was: {$tender->submission_deadline->toIso8601String()}. "
                . "No further bids or revisions are accepted."
            );
        }
    }

    /**
     * Assert that the supplier has no existing bid for this tender.
     *
     * @throws InvalidArgumentException  when a bid already exists
     *
     * Requirements: 8.5
     */
    private function assertNoDuplicateBid(Tender $tender, Supplier $supplier): void
    {
        $exists = Bid::query()
            ->where('tender_id', $tender->id)
            ->where('supplier_id', $supplier->id)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException(
                "You have already submitted a bid for tender '{$tender->reference_number}'. "
                . "Use the PATCH endpoint to revise your existing bid before the deadline."
            );
        }
    }

    /**
     * Assert that the supplier record is active.
     *
     * @throws InvalidArgumentException  when the supplier is not active
     *
     * Requirements: 7.9
     */
    private function assertSupplierActive(Supplier $supplier): void
    {
        if ($supplier->status !== 'active') {
            throw new InvalidArgumentException(
                "Only active suppliers can submit bids. "
                . "Your supplier account status is '{$supplier->status}'."
            );
        }
    }

    /**
     * Assert that the bid belongs to the given supplier.
     *
     * @throws InvalidArgumentException  when the bid does not belong to the supplier
     */
    private function assertBidBelongsToSupplier(Bid $bid, Supplier $supplier): void
    {
        if ($bid->supplier_id !== $supplier->id) {
            throw new InvalidArgumentException(
                "You are not authorized to modify this bid."
            );
        }
    }
}
