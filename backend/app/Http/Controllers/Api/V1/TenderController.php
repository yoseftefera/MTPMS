<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tender\CancelTenderRequest;
use App\Http\Requests\V1\Tender\ExtendTenderDeadlineRequest;
use App\Http\Requests\V1\Tender\PublishTenderRequest;
use App\Http\Requests\V1\Tender\StoreTenderRequest;
use App\Http\Requests\V1\Tender\UpdateTenderRequest;
use App\Http\Requests\V1\Tender\UploadTenderDocumentRequest;
use App\Http\Resources\V1\TenderDocumentResource;
use App\Http\Resources\V1\TenderResource;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Services\TenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TenderController — thin HTTP layer for the tender lifecycle.
 *
 * Endpoints (all under /api/v1/tenders):
 *   GET    /api/v1/tenders                     — paginated, filterable list
 *   POST   /api/v1/tenders                     — create a tender (draft)
 *   GET    /api/v1/tenders/{tender}             — show a single tender
 *   PUT    /api/v1/tenders/{tender}             — update a draft tender
 *   DELETE /api/v1/tenders/{tender}             — soft-delete a draft tender
 *   POST   /api/v1/tenders/{tender}/publish     — publish (draft → published)
 *   POST   /api/v1/tenders/{tender}/cancel      — cancel (draft|published → cancelled)
 *   PATCH  /api/v1/tenders/{tender}/deadline    — extend submission deadline
 *   POST   /api/v1/tenders/{tender}/documents   — upload a document
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding for {tender} implicitly checks the tenant scope.
 *
 * Permission gates are enforced at route level via role.check middleware:
 *   - tenders.view   — index, show
 *   - tenders.create — store, update, destroy, cancel, extendDeadline, uploadDocument
 *   - tenders.publish — publish
 *
 * Requirements: 8.1, 8.2, 8.3, 8.6, 8.8, 8.9, 8.10
 */
class TenderController extends Controller
{
    public function __construct(private readonly TenderService $service) {}

    // =========================================================================
    // GET /api/v1/tenders
    // =========================================================================

    /**
     * Return a paginated, filterable list of tenders for the active tenant.
     *
     * Query parameters:
     *   status       — draft | published | closed | awarded | cancelled
     *   category     — partial match on category
     *   tender_type  — open | restricted | single_source
     *   search       — partial match on title, reference_number, or description
     *   per_page     — results per page (default 20, max 100)
     *
     * Requirements: 8.1
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'status'      => $request->query('status'),
            'category'    => $request->query('category'),
            'tender_type' => $request->query('tender_type'),
            'search'      => $request->query('search'),
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      TenderResource::collection($paginator->items()),
            message:   'Tenders retrieved successfully.',
        );
    }

    // =========================================================================
    // POST /api/v1/tenders
    // =========================================================================

    /**
     * Create a new tender in `draft` status.
     *
     * Requirements: 8.1
     */
    public function store(StoreTenderRequest $request): JsonResponse
    {
        $actor    = Auth::guard('api')->user();
        $tenantId = app('tenant')?->id ?? $actor->tenant_id;

        try {
            $tender = $this->service->create(
                data:      $request->validated(),
                actor:     $actor,
                tenantId:  $tenantId,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new TenderResource($tender),
            message: 'Tender created successfully.',
        );
    }

    // =========================================================================
    // GET /api/v1/tenders/{tender}
    // =========================================================================

    /**
     * Return a single tender with its documents and bid summary.
     *
     * Bids are only visible to Procurement_Officer, Committee_Member, and Tenant_Admin.
     * Suppliers see no bids on the tender detail endpoint (they use the bids sub-resource).
     *
     * Requirements: 8.1, 8.7
     */
    public function show(Tender $tender): JsonResponse
    {
        $tender->load(['documents', 'createdBy', 'bids']);

        return $this->success(
            data:    new TenderResource($tender),
            message: 'Tender retrieved successfully.',
        );
    }

    // =========================================================================
    // PUT /api/v1/tenders/{tender}
    // =========================================================================

    /**
     * Update a draft tender's details.
     *
     * Returns HTTP 422 when the tender is no longer in `draft` status.
     *
     * Requirements: 8.1
     */
    public function update(UpdateTenderRequest $request, Tender $tender): JsonResponse
    {
        if ($tender->status !== 'draft') {
            return $this->error(
                message: "Only draft tenders can be updated (current status: {$tender->status}).",
                status:  422,
                errors:  ['status' => ["Only draft tenders can be updated (current status: {$tender->status})."]],
            );
        }

        $tender->update($request->validated());

        return $this->success(
            data:    new TenderResource($tender->fresh(['documents', 'createdBy'])),
            message: 'Tender updated successfully.',
        );
    }

    // =========================================================================
    // DELETE /api/v1/tenders/{tender}
    // =========================================================================

    /**
     * Soft-delete a draft tender.
     *
     * Returns HTTP 422 when the tender is not in `draft` status (published tenders
     * must be cancelled via the cancel endpoint).
     *
     * Requirements: 8.1
     */
    public function destroy(Tender $tender): JsonResponse
    {
        if ($tender->status !== 'draft') {
            return $this->error(
                message: "Only draft tenders can be deleted. Use the cancel endpoint for published tenders.",
                status:  422,
                errors:  ['status' => ['Only draft tenders can be deleted.']],
            );
        }

        $tender->delete();

        return $this->noContent();
    }

    // =========================================================================
    // POST /api/v1/tenders/{tender}/publish
    // =========================================================================

    /**
     * Publish a tender (draft → published) and notify eligible suppliers.
     *
     * For open/restricted tenders, all active suppliers matching the category
     * receive an in-app notification.
     * For single_source tenders, `supplier_id` must be provided in the request body.
     *
     * Requirements: 8.2, 8.10
     */
    public function publish(PublishTenderRequest $request, Tender $tender): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $published = $this->service->publish(
                tender:    $tender,
                actor:     $actor,
                data:      $request->validated(),
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new TenderResource($published),
            message: 'Tender published successfully. Eligible suppliers have been notified.',
        );
    }

    // =========================================================================
    // POST /api/v1/tenders/{tender}/cancel
    // =========================================================================

    /**
     * Cancel a tender (draft|published → cancelled).
     *
     * After cancellation all suppliers who submitted a bid are notified with
     * the mandatory cancellation reason.
     *
     * Requirements: 8.9
     */
    public function cancel(CancelTenderRequest $request, Tender $tender): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $cancelled = $this->service->cancel(
                tender:               $tender,
                actor:                $actor,
                cancellationReason:   $request->input('cancellation_reason'),
                ipAddress:            $request->ip(),
                requestId:            $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new TenderResource($cancelled),
            message: 'Tender cancelled successfully. Bidding suppliers have been notified.',
        );
    }

    // =========================================================================
    // PATCH /api/v1/tenders/{tender}/deadline
    // =========================================================================

    /**
     * Extend a published tender's submission deadline.
     *
     * The new deadline must be strictly after the current deadline, and the
     * current deadline must not have already passed.
     *
     * Requirements: 8.8
     */
    public function extendDeadline(ExtendTenderDeadlineRequest $request, Tender $tender): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $updated = $this->service->extendDeadline(
                tender:      $tender,
                actor:       $actor,
                newDeadline: $request->input('submission_deadline'),
                ipAddress:   $request->ip(),
                requestId:   $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new TenderResource($updated),
            message: 'Tender deadline extended successfully.',
        );
    }

    // =========================================================================
    // POST /api/v1/tenders/{tender}/documents
    // =========================================================================

    /**
     * Upload a document and attach it to a tender.
     *
     * Accepted file types: PDF, DOCX, XLSX, PNG, JPG, JPEG (max 10 MB).
     * The file is stored in a tenant-scoped path:
     *   {tenant_id}/tenders/{tender_id}/{uuid}.{ext}
     *
     * Returns HTTP 422 when the file type or size is invalid.
     * Returns HTTP 422 when the tender is cancelled.
     *
     * Requirements: 8.3, 23.1, 23.2, 23.3
     */
    public function uploadDocument(UploadTenderDocumentRequest $request, Tender $tender): JsonResponse
    {
        if ($tender->status === 'cancelled') {
            return $this->error(
                message: 'Documents cannot be uploaded to a cancelled tender.',
                status:  422,
                errors:  ['status' => ['Documents cannot be uploaded to a cancelled tender.']],
            );
        }

        $actor = Auth::guard('api')->user();
        $file  = $request->file('file');

        // Generate a non-guessable storage key
        $extension = $file->getClientOriginalExtension();
        $storageKey = Str::uuid() . '-' . hash('sha256', $file->getClientOriginalName() . microtime()) . '.' . $extension;
        $storagePath = "{$tender->tenant_id}/tenders/{$tender->id}/{$storageKey}";

        // Store in the configured disk (S3-compatible or local)
        $path = Storage::disk(config('filesystems.default', 'local'))
            ->putFileAs(
                "{$tender->tenant_id}/tenders/{$tender->id}",
                $file,
                $storageKey,
            );

        $document = TenderDocument::create([
            'tenant_id'     => $tender->tenant_id,
            'tender_id'     => $tender->id,
            'document_type' => $request->input('document_type'),
            'file_name'     => $file->getClientOriginalName(),
            'file_path'     => $storagePath,
            'uploaded_by'   => $actor->id,
            'created_at'    => now(),   // $timestamps = false — must set manually
        ]);

        return $this->created(
            data:    new TenderDocumentResource($document->load('uploadedBy')),
            message: 'Tender document uploaded successfully.',
        );
    }
}
