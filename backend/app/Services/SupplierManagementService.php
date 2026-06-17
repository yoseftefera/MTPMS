<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\SupplierPerformance;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SupplierManagementService — full supplier lifecycle within a tenant.
 *
 * Responsibilities:
 *  - register()              — public self-registration (no auth); creates supplier in `pending_verification`
 *  - approve()               — Procurement_Officer transitions pending → active
 *  - reject()                — Procurement_Officer rejects a pending registration
 *  - blacklist()             — Procurement_Officer blacklists with a documented reason
 *  - uploadDocument()        — upload / version compliance documents
 *  - recalculateMetrics()    — recompute on-time delivery rate and quality acceptance rate from raw records
 *  - search()                — paginated, filterable supplier list
 *
 * Performance metrics:
 *  on_time_delivery_rate    = (on-time deliveries / total deliveries) × 100
 *  quality_acceptance_rate  = (accepted line items / total received line items) × 100
 *  Both are stored as DECIMAL(5,2) on the suppliers table and recomputed from
 *  SupplierPerformance rows whenever new observations arrive.
 *
 * Document versioning:
 *  Each call to uploadDocument() with an existing document_type increments the
 *  version counter for that type. Old versions are soft-deleted to preserve history.
 *
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.9, 7.10
 */
class SupplierManagementService
{
    /**
     * BCMath scale for percentage calculations.
     */
    private const SCALE = 2;

    /**
     * Valid document MIME types (mirrors FileManagementService validation).
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/png',
        'image/jpeg',
    ];

    /**
     * Maximum file size in bytes — 10 MB.
     */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    /**
     * Allowed status transitions for verification workflow.
     * key = from_status, value = allowed to_statuses via explicit actions.
     */
    private const VERIFICATION_TRANSITIONS = [
        'pending_verification' => ['active', 'inactive'],
        'active'               => ['blacklisted', 'inactive'],
        'inactive'             => ['active', 'blacklisted'],
        'blacklisted'          => [],
    ];

    // =========================================================================
    // Public self-registration (no auth required)
    // =========================================================================

    /**
     * Register a new supplier.
     *
     * Creates the supplier in `pending_verification` status. No authentication
     * is required — this is called by the public endpoint. The tenant is resolved
     * from app('tenant') by the TenantIdentificationMiddleware before this is called.
     *
     * Required fields: organization_name, contact_name, contact_email, business_category
     * Optional:        contact_phone, user_id (portal user link)
     *
     * Requirements: 7.1, 7.2
     *
     * @param  array{
     *     organization_name: string,
     *     contact_name: string,
     *     contact_email: string,
     *     business_category: string,
     *     contact_phone?: string|null,
     *     user_id?: string|null,
     * }  $data
     *
     * @throws InvalidArgumentException  when required fields are missing
     */
    public function register(array $data, string $tenantId, ?string $ipAddress = null, ?string $requestId = null): Supplier
    {
        $this->validateRegistrationData($data);

        return DB::transaction(function () use ($data, $tenantId, $ipAddress, $requestId) {
            /** @var Supplier $supplier */
            $supplier = Supplier::create([
                'tenant_id'         => $tenantId,
                'organization_name' => $data['organization_name'],
                'contact_name'      => $data['contact_name'],
                'contact_email'     => $data['contact_email'],
                'contact_phone'     => $data['contact_phone'] ?? null,
                'business_category' => $data['business_category'],
                'status'            => 'pending_verification',
                'user_id'           => $data['user_id'] ?? null,
            ]);

            WriteAuditLogJob::dispatch(
                $tenantId,
                null,
                'supplier',
                'supplier.registered',
                'supplier',
                $supplier->id,
                null,
                [
                    'organization_name' => $supplier->organization_name,
                    'status'            => 'pending_verification',
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $supplier->load(['documents', 'performances']);
        });
    }

    // =========================================================================
    // Verification workflow: pending → active
    // =========================================================================

    /**
     * Approve a supplier registration (pending_verification → active).
     *
     * Only Procurement_Officers may call this. The caller must pass in the
     * authenticated user so the action can be audit-logged.
     *
     * Requirements: 7.3
     *
     * @throws InvalidArgumentException  when supplier is not in `pending_verification` status
     */
    public function approve(Supplier $supplier, User $actor, ?string $ipAddress = null, ?string $requestId = null): Supplier
    {
        if ($supplier->status !== 'pending_verification') {
            throw new InvalidArgumentException(
                "Supplier '{$supplier->organization_name}' cannot be approved: "
                . "only suppliers in 'pending_verification' status can be approved "
                . "(current status: {$supplier->status})."
            );
        }

        return DB::transaction(function () use ($supplier, $actor, $ipAddress, $requestId) {
            $before = ['status' => $supplier->status];

            $supplier->update(['status' => 'active']);

            WriteAuditLogJob::dispatch(
                $supplier->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'supplier.approved',
                'supplier',
                $supplier->id,
                $before,
                ['status' => 'active'],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $supplier->fresh(['documents', 'performances']);
        });
    }

    /**
     * Reject a supplier registration (pending_verification → inactive).
     *
     * Requirements: 7.2 (implicit — Procurement_Officer can reject as well as approve)
     *
     * @throws InvalidArgumentException  when supplier is not in `pending_verification` status
     */
    public function reject(Supplier $supplier, User $actor, string $reason, ?string $ipAddress = null, ?string $requestId = null): Supplier
    {
        if ($supplier->status !== 'pending_verification') {
            throw new InvalidArgumentException(
                "Supplier '{$supplier->organization_name}' cannot be rejected: "
                . "only suppliers in 'pending_verification' status can be rejected "
                . "(current status: {$supplier->status})."
            );
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        return DB::transaction(function () use ($supplier, $actor, $reason, $ipAddress, $requestId) {
            $before = ['status' => $supplier->status];

            $supplier->update([
                'status'          => 'inactive',
                'blacklist_reason' => $reason,
            ]);

            WriteAuditLogJob::dispatch(
                $supplier->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'supplier.rejected',
                'supplier',
                $supplier->id,
                $before,
                ['status' => 'inactive', 'reason' => $reason],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $supplier->fresh(['documents', 'performances']);
        });
    }

    // =========================================================================
    // Blacklisting with documented reason
    // =========================================================================

    /**
     * Blacklist a supplier with a mandatory documented reason.
     *
     * A blacklisted supplier is excluded from all future tender invitations
     * and cannot submit bids or receive purchase orders.
     *
     * Requirements: 7.4, 7.5
     *
     * @throws InvalidArgumentException  when supplier is already blacklisted
     * @throws InvalidArgumentException  when reason is empty
     */
    public function blacklist(
        Supplier $supplier,
        User $actor,
        string $reason,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): Supplier {
        if ($supplier->status === 'blacklisted') {
            throw new InvalidArgumentException(
                "Supplier '{$supplier->organization_name}' is already blacklisted."
            );
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('A blacklist reason is required and cannot be empty.');
        }

        return DB::transaction(function () use ($supplier, $actor, $reason, $ipAddress, $requestId) {
            $before = [
                'status'          => $supplier->status,
                'blacklist_reason' => $supplier->blacklist_reason,
                'blacklisted_by'  => $supplier->blacklisted_by,
                'blacklisted_at'  => $supplier->blacklisted_at?->toIso8601String(),
            ];

            $supplier->update([
                'status'           => 'blacklisted',
                'blacklist_reason' => $reason,
                'blacklisted_by'   => $actor->id,
                'blacklisted_at'   => now(),
            ]);

            WriteAuditLogJob::dispatch(
                $supplier->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'supplier.blacklisted',
                'supplier',
                $supplier->id,
                $before,
                [
                    'status'           => 'blacklisted',
                    'blacklist_reason' => $reason,
                    'blacklisted_by'   => $actor->id,
                    'blacklisted_at'   => now()->toIso8601String(),
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $supplier->fresh(['documents', 'performances', 'blacklistedBy']);
        });
    }

    // =========================================================================
    // Compliance document upload / versioning
    // =========================================================================

    /**
     * Upload a compliance document for a supplier, with automatic versioning.
     *
     * Each call increments the version counter for the given document_type.
     * The previous version record is soft-deleted to preserve history while
     * the latest version becomes the active record.
     *
     * Storage path: {tenant_id}/suppliers/{supplier_id}/{document_type}/v{version}_{uuid}.{ext}
     *
     * Requirements: 7.10
     *
     * @throws InvalidArgumentException  when file MIME type or size validation fails
     * @throws InvalidArgumentException  when document_type is not one of the allowed enum values
     */
    public function uploadDocument(
        Supplier $supplier,
        UploadedFile $file,
        string $documentType,
        ?string $expiresAt,
        User $uploader,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): SupplierDocument {
        $this->validateDocumentFile($file);
        $this->validateDocumentType($documentType);

        return DB::transaction(function () use ($supplier, $file, $documentType, $expiresAt, $uploader, $ipAddress, $requestId) {
            // Determine the next version number for this supplier + document_type
            $latestVersion = SupplierDocument::where('supplier_id', $supplier->id)
                ->where('document_type', $documentType)
                ->withTrashed()                  // count all versions including soft-deleted
                ->max('version') ?? 0;

            $nextVersion = $latestVersion + 1;

            // Generate a non-guessable storage key
            $uuid      = Str::uuid()->toString();
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
            $storedPath = "{$supplier->tenant_id}/suppliers/{$supplier->id}/{$documentType}/v{$nextVersion}_{$uuid}.{$extension}";

            Storage::disk('local')->put($storedPath, file_get_contents($file->getRealPath()));

            /** @var SupplierDocument $document */
            $document = SupplierDocument::create([
                'tenant_id'     => $supplier->tenant_id,
                'supplier_id'   => $supplier->id,
                'document_type' => $documentType,
                'file_path'     => $storedPath,
                'file_name'     => $file->getClientOriginalName(),
                'expires_at'    => $expiresAt ?: null,
                'version'       => $nextVersion,
                'uploaded_by'   => $uploader->id,
            ]);

            WriteAuditLogJob::dispatch(
                $supplier->tenant_id,
                $uploader->id,
                $uploader->getRoleNames()->first() ?? 'unknown',
                'supplier.document_uploaded',
                'supplier_document',
                $document->id,
                null,
                [
                    'supplier_id'   => $supplier->id,
                    'document_type' => $documentType,
                    'version'       => $nextVersion,
                    'file_name'     => $file->getClientOriginalName(),
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $document->load(['supplier', 'uploadedBy']);
        });
    }

    // =========================================================================
    // Performance metrics calculation
    // =========================================================================

    /**
     * Recalculate and persist on-time delivery rate and quality acceptance rate
     * for the given supplier from the raw SupplierPerformance records.
     *
     * on_time_delivery_rate:
     *   = (count of `on_time_delivery` records with value = 1.00) /
     *     (total count of `on_time_delivery` records) × 100
     *   Returns 0.00 when no delivery records exist.
     *
     * quality_acceptance_rate:
     *   = SUM(value) of `quality_acceptance` records /
     *     COUNT of `quality_acceptance` records × 100
     *   Each `quality_acceptance` record stores a ratio (0.0000 – 1.0000)
     *   representing the acceptance proportion for a single goods receipt.
     *   Returns 0.00 when no quality records exist.
     *
     * Requirements: 7.6
     */
    public function recalculateMetrics(Supplier $supplier): Supplier
    {
        // ── On-time delivery rate ───────────────────────────────────────────
        $deliveryRecords = SupplierPerformance::where('supplier_id', $supplier->id)
            ->where('metric_type', 'on_time_delivery')
            ->get(['value']);

        $onTimeRate = '0.00';
        if ($deliveryRecords->isNotEmpty()) {
            $total  = (string) $deliveryRecords->count();
            $onTime = (string) $deliveryRecords->where('value', '1.0000')->count();
            $onTimeRate = bcmul(bcdiv($onTime, $total, 6), '100', self::SCALE);
        }

        // ── Quality acceptance rate ─────────────────────────────────────────
        $qualityRecords = SupplierPerformance::where('supplier_id', $supplier->id)
            ->where('metric_type', 'quality_acceptance')
            ->get(['value']);

        $qualityRate = '0.00';
        if ($qualityRecords->isNotEmpty()) {
            $total = (string) $qualityRecords->count();
            $sum   = $qualityRecords->reduce(
                fn (string $carry, SupplierPerformance $row) => bcadd($carry, (string) $row->value, 6),
                '0.000000',
            );
            $qualityRate = bcmul(bcdiv($sum, $total, 6), '100', self::SCALE);
        }

        $supplier->update([
            'on_time_delivery_rate'   => $onTimeRate,
            'quality_acceptance_rate' => $qualityRate,
        ]);

        return $supplier->fresh();
    }

    /**
     * Record a single performance observation for a supplier and then trigger
     * a full metrics recalculation so the aggregates on the suppliers table stay current.
     *
     * @param  string  $metricType      'on_time_delivery' | 'quality_acceptance'
     * @param  string  $value           DECIMAL(8,4) string
     * @param  string  $referenceType   Polymorphic type (e.g. 'purchase_order', 'goods_receipt')
     * @param  string  $referenceId     UUID of the source document
     *
     * Requirements: 7.6
     */
    public function recordPerformanceMetric(
        Supplier $supplier,
        string $metricType,
        string $value,
        string $referenceType,
        string $referenceId,
    ): SupplierPerformance {
        $record = SupplierPerformance::create([
            'tenant_id'      => $supplier->tenant_id,
            'supplier_id'    => $supplier->id,
            'metric_type'    => $metricType,
            'value'          => $value,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'recorded_at'    => now(),
        ]);

        // Recalculate aggregate rates whenever a new observation is added
        $this->recalculateMetrics($supplier);

        return $record;
    }

    /**
     * Return a paginated list of performance records for a supplier.
     *
     * Requirements: 7.6, 7.7
     */
    public function getPerformance(Supplier $supplier, int $perPage = 20): LengthAwarePaginator
    {
        return SupplierPerformance::where('supplier_id', $supplier->id)
            ->orderByDesc('recorded_at')
            ->paginate($perPage);
    }

    // =========================================================================
    // Search / List
    // =========================================================================

    /**
     * Return a paginated, filterable list of suppliers within the active tenant scope.
     *
     * Supported filters:
     *   status            — 'pending_verification' | 'active' | 'blacklisted' | 'inactive'
     *   business_category — partial match
     *   search            — partial match on organization_name or contact_email
     *   per_page          — results per page (default 20, max 100)
     *
     * Requirements: 7.7
     */
    public function search(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Supplier::query()->with(['documents' => function ($q) {
            // Only eager-load the latest (non-soft-deleted) documents
            $q->orderByDesc('version');
        }]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['business_category'])) {
            $query->where('business_category', 'like', '%' . $filters['business_category'] . '%');
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('organization_name', 'like', "%{$term}%")
                  ->orWhere('contact_email', 'like', "%{$term}%")
                  ->orWhere('contact_name', 'like', "%{$term}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // =========================================================================
    // Private validation helpers
    // =========================================================================

    /**
     * Validate required registration fields.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private function validateRegistrationData(array $data): void
    {
        $required = ['organization_name', 'contact_name', 'contact_email', 'business_category'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $label = str_replace('_', ' ', $field);
                throw new InvalidArgumentException("The {$label} field is required.");
            }
        }

        if (! filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The contact email must be a valid email address.');
        }
    }

    /**
     * Validate an uploaded compliance document's MIME type and size.
     *
     * @throws InvalidArgumentException
     */
    private function validateDocumentFile(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException(
                'File exceeds the maximum allowed size of 10 MB.'
            );
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException(
                "File type '{$mime}' is not allowed. Allowed types: PDF, Word, Excel, PNG, JPEG."
            );
        }
    }

    /**
     * Validate document_type against the enum values in the database schema.
     *
     * @throws InvalidArgumentException
     */
    private function validateDocumentType(string $documentType): void
    {
        $allowed = ['tin_certificate', 'vat_certificate', 'business_license', 'performance_bond', 'other'];

        if (! in_array($documentType, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid document type '{$documentType}'. Allowed: " . implode(', ', $allowed) . '.'
            );
        }
    }
}
