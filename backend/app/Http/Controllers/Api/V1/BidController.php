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
     * Return a paginated list of bids for the given tender.
     *
     * Suppliers only see their own bid.
     * Officers and admins see all bids for the tender.
     *
     * Query parameters:
     *   per_page — results per page (default 20, max 100)
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
     * Submit a new bid for the given tender.
     *
     * Rules enforced:
     *  - Tender must be `published` and submission_deadline must not have passed (Req 8.4).
     *  - Supplier must not have an existing bid for this tender (Req 8.5).
     *  - Supplier must be active (Req 7.9).
     *
     * Returns HTTP 422 when any business rule is violated (deadline passed, duplicate bid, etc.).
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
     * Return a single bid.
     *
     * Suppliers may only view their own bid — returns HTTP 404 for others' bids.
     * Officers and admins may view any bid for the tender.
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
     * Revise an existing bid before the tender's submission deadline.
     *
     * Returns HTTP 422 when the deadline has passed or the bid does not
     * belong to the authenticated supplier.
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
     * Upload a document and attach it to an existing bid.
     *
     * Returns HTTP 422 when the deadline has passed or the bid does not
     * belong to the authenticated supplier.
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
