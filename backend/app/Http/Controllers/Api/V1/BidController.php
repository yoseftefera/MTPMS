<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Bid\StoreBidRequest;
use App\Http\Requests\V1\Bid\UpdateBidRequest;
use App\Http\Requests\V1\Bid\UploadBidDocumentRequest;
use App\Http\Resources\V1\BidDocumentResource;
use App\Http\Resources\V1\BidResource;
use App\Models\Bid;
use App\Models\Supplier;
use App\Models\Tender;
use App\Services\BidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * @OA\Tag(name="Bids", description="Bid submission and revision for tenders. Includes document uploads.")
 *
 * BidController — thin HTTP layer for the bid submission lifecycle.
 *
 * Endpoints (all nested under /api/v1/tenders/{tender}/bids):
 *   GET    /api/v1/tenders/{tender}/bids                     — list bids (role-isolated)
 *   POST   /api/v1/tenders/{tender}/bids                     — submit a new bid
 *   GET    /api/v1/tenders/{tender}/bids/{bid}               — show a single bid (role-isolated)
 *   PATCH  /api/v1/tenders/{tender}/bids/{bid}               — revise a bid before deadline
 *   POST   /api/v1/tenders/{tender}/bids/{bid}/documents     — upload document to a bid
 *
 * Visibility rules enforced (Req 8.7):
 *   - Supplier role → GET endpoints return only their own bid(s); HTTP 404 for others.
 *   - Procurement_Officer / Tenant_Admin / Committee_Member → see all bids for the tender.
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding for {bid} implicitly checks the tenant scope.
 *
 * Requirements: 8.4, 8.5, 8.7
 */
class BidController extends Controller
{
    public function __construct(private readonly BidService $service) {}

    // =========================================================================
    // GET /api/v1/tenders/{tender}/bids
    // =========================================================================

    /**
     * @OA\Get(
     *     path="/tenders/{tender}/bids",
     *     operationId="listBids",
     *     tags={"Bids"},
     *     summary="List bids for a tender",
     *     description="Suppliers only see their own bid. Procurement officers and admins see all bids.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Bids list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/BidResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of bids for the given tender.
     *
     * Requirements: 8.7
     */
    public function index(Request $request, Tender $tender): JsonResponse
    {
        $perPage  = min((int) $request->query('per_page', 20), 100);
        $user     = Auth::guard('api')->user();
        $role     = $user?->getRoleNames()->first();
        $supplier = $this->resolveSupplierForUser($user, $tender->tenant_id);

        $paginator = $this->service->getBidsForTender(
            tender:               $tender,
            roleForIsolation:     $role,
            supplierForIsolation: $supplier,
            perPage:              $perPage,
        );

        return $this->paginated(
            paginator: $paginator,
            data:      BidResource::collection($paginator->items()),
            message:   'Bids retrieved successfully.',
        );
    }

    // =========================================================================
    // POST /api/v1/tenders/{tender}/bids
    // =========================================================================

    /**
     * @OA\Post(
     *     path="/tenders/{tender}/bids",
     *     operationId="submitBid",
     *     tags={"Bids"},
     *     summary="Submit bid for a tender",
     *     description="Validates submission timestamp against deadline. Enforces one bid per supplier. Supplier must be active.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"total_amount","delivery_days"},
     *         @OA\Property(property="total_amount", type="string", example="195000.00"),
     *         @OA\Property(property="currency", type="string", example="USD"),
     *         @OA\Property(property="delivery_days", type="integer", example=30),
     *         @OA\Property(property="technical_notes", type="string", nullable=true)
     *     )),
     *     @OA\Response(response=201, description="Bid submitted.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/BidResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Deadline passed, duplicate bid, or supplier not active.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Submit a new bid for the given tender.
     *
     * Requirements: 8.4, 8.5
     */
    public function store(StoreBidRequest $request, Tender $tender): JsonResponse
    {
        $user     = Auth::guard('api')->user();
        $supplier = $this->resolveSupplierForUser($user, $tender->tenant_id);

        if (! $supplier) {
            return $this->error(
                message: 'No active supplier profile is linked to your account. '
                    . 'Please contact the Procurement Officer to link your account to a supplier record.',
                status:  422,
                errors:  ['supplier' => ['No supplier profile linked to this account.']],
            );
        }

        try {
            $bid = $this->service->submit(
                tender:     $tender,
                supplier:   $supplier,
                data:       $request->validated(),
                actor:      $user,
                ipAddress:  $request->ip(),
                requestId:  $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new BidResource($bid),
            message: 'Bid submitted successfully.',
        );
    }

    // =========================================================================
    // GET /api/v1/tenders/{tender}/bids/{bid}
    // =========================================================================

    /**
     * @OA\Get(
     *     path="/tenders/{tender}/bids/{bid}",
     *     operationId="showBid",
     *     tags={"Bids"},
     *     summary="Get bid",
     *     description="Suppliers may only view their own bid — returns HTTP 404 for others' bids.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="bid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Bid returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/BidResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single bid.
     *
     * Requirements: 8.7
     */
    public function show(Tender $tender, Bid $bid): JsonResponse
    {
        $user     = Auth::guard('api')->user();
        $role     = $user?->getRoleNames()->first();
        $supplier = $this->resolveSupplierForUser($user, $tender->tenant_id);

        // Confirm the bid actually belongs to this tender (extra safety check)
        if ($bid->tender_id !== $tender->id) {
            return $this->error('Bid not found.', 404);
        }

        $result = $this->service->getBid(
            bid:                  $bid,
            roleForIsolation:     $role,
            supplierForIsolation: $supplier,
        );

        if ($result === null) {
            return $this->error('Bid not found.', 404);
        }

        return $this->success(
            data:    new BidResource($result),
            message: 'Bid retrieved successfully.',
        );
    }

    // =========================================================================
    // PATCH /api/v1/tenders/{tender}/bids/{bid}
    // =========================================================================

    /**
     * @OA\Patch(
     *     path="/tenders/{tender}/bids/{bid}",
     *     operationId="reviseBid",
     *     tags={"Bids"},
     *     summary="Revise bid before deadline",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="bid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="total_amount", type="string", example="190000.00"), @OA\Property(property="delivery_days", type="integer", example=25), @OA\Property(property="technical_notes", type="string", nullable=true))),
     *     @OA\Response(response=200, description="Bid revised.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/BidResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Deadline passed or bid does not belong to supplier.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Revise an existing bid before the tender's submission deadline.
     *
     * Requirements: 8.4, 8.5
     */
    public function update(UpdateBidRequest $request, Tender $tender, Bid $bid): JsonResponse
    {
        $user     = Auth::guard('api')->user();
        $supplier = $this->resolveSupplierForUser($user, $tender->tenant_id);

        if (! $supplier) {
            return $this->error(
                message: 'No supplier profile is linked to your account.',
                status:  422,
                errors:  ['supplier' => ['No supplier profile linked to this account.']],
            );
        }

        // Confirm the bid belongs to this tender
        if ($bid->tender_id !== $tender->id) {
            return $this->error('Bid not found.', 404);
        }

        try {
            $updated = $this->service->revise(
                tender:    $tender,
                bid:       $bid,
                supplier:  $supplier,
                data:      $request->validated(),
                actor:     $user,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new BidResource($updated),
            message: 'Bid revised successfully.',
        );
    }

    // =========================================================================
    // POST /api/v1/tenders/{tender}/bids/{bid}/documents
    // =========================================================================

    /**
     * @OA\Post(
     *     path="/tenders/{tender}/bids/{bid}/documents",
     *     operationId="uploadBidDocument",
     *     tags={"Bids"},
     *     summary="Upload document to bid",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="bid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(required={"file","document_type"}, @OA\Property(property="file", type="string", format="binary"), @OA\Property(property="document_type", type="string", example="technical_proposal")))),
     *     @OA\Response(response=201, description="Document uploaded.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Deadline passed or access denied.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Upload a document and attach it to an existing bid.
     *
     * Requirements: 8.4, 8.5
     */
    public function uploadDocument(UploadBidDocumentRequest $request, Tender $tender, Bid $bid): JsonResponse
    {
        $user     = Auth::guard('api')->user();
        $supplier = $this->resolveSupplierForUser($user, $tender->tenant_id);

        if (! $supplier) {
            return $this->error(
                message: 'No supplier profile is linked to your account.',
                status:  422,
                errors:  ['supplier' => ['No supplier profile linked to this account.']],
            );
        }

        // Confirm the bid belongs to this tender
        if ($bid->tender_id !== $tender->id) {
            return $this->error('Bid not found.', 404);
        }

        try {
            $document = $this->service->uploadDocument(
                tender:       $tender,
                bid:          $bid,
                supplier:     $supplier,
                file:         $request->file('file'),
                documentType: $request->input('document_type'),
                actor:        $user,
                ipAddress:    $request->ip(),
                requestId:    $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new BidDocumentResource($document),
            message: 'Bid document uploaded successfully.',
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the Supplier record for the authenticated user within the given tenant.
     *
     * The Supplier record links a User to their supplier profile via `suppliers.user_id`.
     * Returns null when the user is not linked to a supplier (e.g. Procurement_Officer).
     */
    private function resolveSupplierForUser(?object $user, string $tenantId): ?Supplier
    {
        if (! $user) {
            return null;
        }

        return Supplier::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first();
    }
}
