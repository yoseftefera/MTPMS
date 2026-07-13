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
 * @OA\Tag(name="Tenders", description="Tender lifecycle: create, publish, cancel, extend deadline, and document uploads.")
 *
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
     * @OA\Get(
     *     path="/tenders",
     *     operationId="listTenders",
     *     tags={"Tenders"},
     *     summary="List tenders",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft","published","closed","awarded","cancelled"})),
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="tender_type", in="query", required=false, @OA\Schema(type="string", enum={"open","restricted","single_source"})),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="Partial match on title, reference_number, or description."),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Tenders list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TenderResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated, filterable list of tenders for the active tenant.
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
     * @OA\Post(
     *     path="/tenders",
     *     operationId="createTender",
     *     tags={"Tenders"},
     *     summary="Create tender",
     *     description="Creates a new tender in draft status.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"title","description","category","tender_type","estimated_value","submission_deadline"},
     *         @OA\Property(property="title", type="string", example="Supply of Office Furniture 2025"),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="category", type="string", example="Furniture"),
     *         @OA\Property(property="tender_type", type="string", enum={"open","restricted","single_source"}, example="open"),
     *         @OA\Property(property="estimated_value", type="string", example="250000.00"),
     *         @OA\Property(property="submission_deadline", type="string", format="date-time", example="2025-06-30T23:59:59Z")
     *     )),
     *     @OA\Response(response=201, description="Tender created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Get(
     *     path="/tenders/{tender}",
     *     operationId="showTender",
     *     tags={"Tenders"},
     *     summary="Get tender",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Tender with documents and bid summary.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single tender with its documents and bid summary.
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
     * @OA\Put(
     *     path="/tenders/{tender}",
     *     operationId="updateTender",
     *     tags={"Tenders"},
     *     summary="Update draft tender",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="title", type="string"), @OA\Property(property="description", type="string"), @OA\Property(property="category", type="string"), @OA\Property(property="estimated_value", type="string"), @OA\Property(property="submission_deadline", type="string", format="date-time"))),
     *     @OA\Response(response=200, description="Tender updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Tender not in draft status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Update a draft tender's details.
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
     * @OA\Delete(
     *     path="/tenders/{tender}",
     *     operationId="deleteTender",
     *     tags={"Tenders"},
     *     summary="Delete draft tender",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=204, description="Tender deleted (no content).", ),
     *     @OA\Response(response=422, description="Tender not in draft status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Soft-delete a draft tender.
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
     * @OA\Post(
     *     path="/tenders/{tender}/publish",
     *     operationId="publishTender",
     *     tags={"Tenders"},
     *     summary="Publish tender",
     *     description="Transitions tender from draft to published and notifies eligible active suppliers.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=false, @OA\JsonContent(@OA\Property(property="supplier_id", type="string", format="uuid", nullable=true, description="Required only for single_source tenders."))),
     *     @OA\Response(response=200, description="Tender published.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid state transition.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Publish a tender (draft → published) and notify eligible suppliers.
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
     * @OA\Post(
     *     path="/tenders/{tender}/cancel",
     *     operationId="cancelTender",
     *     tags={"Tenders"},
     *     summary="Cancel tender",
     *     description="Cancels a draft or published tender. Notifies all suppliers who submitted bids.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"cancellation_reason"}, @OA\Property(property="cancellation_reason", type="string", example="Budget cuts have required cancellation of this procurement."))),
     *     @OA\Response(response=200, description="Tender cancelled.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Cannot cancel in current state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Cancel a tender (draft|published → cancelled).
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
     * @OA\Patch(
     *     path="/tenders/{tender}/deadline",
     *     operationId="extendTenderDeadline",
     *     tags={"Tenders"},
     *     summary="Extend tender submission deadline",
     *     description="New deadline must be after the current deadline and the current deadline must not have passed.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"submission_deadline"}, @OA\Property(property="submission_deadline", type="string", format="date-time", example="2025-07-31T23:59:59Z"))),
     *     @OA\Response(response=200, description="Deadline extended.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid deadline.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Extend a published tender's submission deadline.
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
     * @OA\Post(
     *     path="/tenders/{tender}/documents",
     *     operationId="uploadTenderDocument",
     *     tags={"Tenders"},
     *     summary="Upload document to tender",
     *     description="Allowed types: PDF, DOCX, XLSX, PNG, JPG, JPEG. Max 10 MB. Stored in tenant-scoped path.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(required={"file","document_type"}, @OA\Property(property="file", type="string", format="binary"), @OA\Property(property="document_type", type="string", example="specifications")))),
     *     @OA\Response(response=201, description="Document uploaded.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid file or cancelled tender.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Upload a document and attach it to a tender.
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
