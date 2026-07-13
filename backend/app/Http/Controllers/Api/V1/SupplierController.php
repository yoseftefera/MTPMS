<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Supplier\BlacklistSupplierRequest;
use App\Http\Requests\V1\Supplier\RegisterSupplierRequest;
use App\Http\Requests\V1\Supplier\RejectSupplierRequest;
use App\Http\Requests\V1\Supplier\UploadSupplierDocumentRequest;
use App\Http\Resources\V1\SupplierDocumentResource;
use App\Http\Resources\V1\SupplierPerformanceResource;
use App\Http\Resources\V1\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * @OA\Tag(name="Suppliers", description="Supplier registration, verification, blacklisting, compliance documents, and performance metrics.")
 *
 * SupplierController — thin HTTP layer for supplier lifecycle management.
 *
 * Public endpoint (no auth):
 *
 *   POST   /api/v1/suppliers/register                         — self-registration
 *
 * Protected endpoints (auth.jwt + role.check:suppliers.view):
 *   GET    /api/v1/suppliers                                  — paginated list with filters
 *   GET    /api/v1/suppliers/{supplier}                       — single supplier detail
 *   PUT    /api/v1/suppliers/{supplier}                       — update supplier profile
 *   DELETE /api/v1/suppliers/{supplier}                       — soft-delete (inactive)
 *   POST   /api/v1/suppliers/{supplier}/approve               — approve registration
 *   POST   /api/v1/suppliers/{supplier}/reject                — reject registration
 *   POST   /api/v1/suppliers/{supplier}/blacklist             — blacklist with reason
 *   GET    /api/v1/suppliers/{supplier}/performance           — performance metrics list
 *   POST   /api/v1/suppliers/{supplier}/documents             — upload compliance document
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding returns HTTP 404 when the supplier belongs to a different tenant.
 *
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.9, 7.10
 */
class SupplierController extends Controller
{
    public function __construct(private readonly SupplierManagementService $service) {}

    // -------------------------------------------------------------------------
    // POST /api/v1/suppliers/register  (PUBLIC — no auth)
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/suppliers/register",
     *     operationId="supplierRegister",
     *     tags={"Suppliers"},
     *     summary="Self-register as a supplier (public)",
     *     description="Any external party can submit a supplier registration. Tenant is resolved via X-Tenant-ID header or subdomain. Returns HTTP 201 with status pending_verification.",
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"),
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"organization_name","contact_name","contact_email","business_category"},
     *         @OA\Property(property="organization_name", type="string", example="Acme Supplies Ltd."),
     *         @OA\Property(property="contact_name", type="string", example="John Smith"),
     *         @OA\Property(property="contact_email", type="string", format="email", example="john@acmesupplies.com"),
     *         @OA\Property(property="contact_phone", type="string", nullable=true, example="+1-555-0199"),
     *         @OA\Property(property="business_category", type="string", example="Office Supplies")
     *     )),
     *     @OA\Response(response=201, description="Registration submitted — pending_verification.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/SupplierResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Self-registration endpoint — any external party can submit this.
     * Tenant is resolved via TenantIdentificationMiddleware (subdomain / header).
     *
     * Returns HTTP 201 with the new supplier record in `pending_verification` status.
     *
     * Requirements: 7.1, 7.2
     */
    public function register(RegisterSupplierRequest $request): JsonResponse
    {
        $tenant = app('tenant');

        if (! $tenant) {
            return $this->error('Tenant could not be resolved.', 401);
        }

        try {
            $supplier = $this->service->register(
                data:       $request->validated(),
                tenantId:   $tenant->id,
                ipAddress:  $request->ip(),
                requestId:  $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new SupplierResource($supplier),
            message: 'Supplier registration submitted successfully. Your application is pending verification.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/suppliers
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(
     *     path="/suppliers",
     *     operationId="listSuppliers",
     *     tags={"Suppliers"},
     *     summary="List suppliers",
     *     description="Returns a paginated, filterable list of suppliers for the active tenant.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"pending_verification","active","blacklisted","inactive"})),
     *     @OA\Parameter(name="business_category", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", required=false, @OA\Schema(type="string"), description="Partial match on organization_name, contact_name, or contact_email."),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Suppliers list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/SupplierResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated, filterable list of suppliers for the active tenant.
     *
     * Requirements: 7.7
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'status'            => $request->query('status'),
            'business_category' => $request->query('business_category'),
            'search'            => $request->query('search'),
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      SupplierResource::collection($paginator->items()),
            message:   'Suppliers retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/suppliers/{supplier}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(
     *     path="/suppliers/{supplier}",
     *     operationId="showSupplier",
     *     tags={"Suppliers"},
     *     summary="Get supplier",
     *     description="Returns a single supplier with documents and performance metrics.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Supplier returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/SupplierResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single supplier with their documents and performance metrics.
     *
     * Requirements: 7.7
     */
    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->load(['documents', 'performances', 'blacklistedBy']);

        return $this->success(
            data:    new SupplierResource($supplier),
            message: 'Supplier retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/suppliers/{supplier}
    // -------------------------------------------------------------------------

    /**
     * @OA\Put(
     *     path="/suppliers/{supplier}",
     *     operationId="updateSupplier",
     *     tags={"Suppliers"},
     *     summary="Update supplier profile",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="organization_name", type="string"), @OA\Property(property="contact_name", type="string"), @OA\Property(property="contact_email", type="string", format="email"), @OA\Property(property="contact_phone", type="string", nullable=true), @OA\Property(property="business_category", type="string"))),
     *     @OA\Response(response=200, description="Supplier updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/SupplierResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Update a supplier's profile fields.
     *
     * Requirements: 7.7
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'organization_name' => ['sometimes', 'string', 'max:255'],
            'contact_name'      => ['sometimes', 'string', 'max:255'],
            'contact_email'     => ['sometimes', 'email', 'max:255'],
            'contact_phone'     => ['nullable', 'string', 'max:50'],
            'business_category' => ['sometimes', 'string', 'max:100'],
        ]);

        $supplier->update($data);

        return $this->success(
            data:    new SupplierResource($supplier->fresh(['documents', 'performances'])),
            message: 'Supplier updated successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/suppliers/{supplier}
    // -------------------------------------------------------------------------

    /**
     * @OA\Delete(
     *     path="/suppliers/{supplier}",
     *     operationId="deleteSupplier",
     *     tags={"Suppliers"},
     *     summary="Delete (deactivate) supplier",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=204, description="Supplier deactivated (no content).", ),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Soft-delete a supplier (sets status to inactive and marks deleted_at).
     *
     * Requirements: 7.7
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->update(['status' => 'inactive']);
        $supplier->delete();

        return $this->noContent();
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/suppliers/{supplier}/approve
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/suppliers/{supplier}/approve",
     *     operationId="approveSupplier",
     *     tags={"Suppliers"},
     *     summary="Approve supplier registration",
     *     description="Transitions supplier from pending_verification to active. Sends confirmation email.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Supplier approved.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/SupplierResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid state transition.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Approve a pending supplier registration (pending_verification → active).
     *
     * Requirements: 7.3
     */
    public function approve(Request $request, Supplier $supplier): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $supplier = $this->service->approve(
                supplier:   $supplier,
                actor:      $actor,
                ipAddress:  $request->ip(),
                requestId:  $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new SupplierResource($supplier),
            message: 'Supplier approved successfully. The supplier is now active.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/suppliers/{supplier}/reject
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/suppliers/{supplier}/reject",
     *     operationId="rejectSupplier",
     *     tags={"Suppliers"},
     *     summary="Reject supplier registration",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Incomplete documentation."))),
     *     @OA\Response(response=200, description="Supplier rejected.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/SupplierResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Reject a pending supplier registration (pending_verification → inactive).
     *
     * Requirements: 7.2
     */
    public function reject(RejectSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $supplier = $this->service->reject(
                supplier:   $supplier,
                actor:      $actor,
                reason:     $request->input('reason'),
                ipAddress:  $request->ip(),
                requestId:  $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new SupplierResource($supplier),
            message: 'Supplier registration rejected.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/suppliers/{supplier}/blacklist
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/suppliers/{supplier}/blacklist",
     *     operationId="blacklistSupplier",
     *     tags={"Suppliers"},
     *     summary="Blacklist supplier",
     *     description="Blacklists a supplier with a mandatory documented reason. Blacklisted suppliers cannot submit bids or receive POs. Recorded in the audit log.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Multiple delivery failures and misrepresentation of quality."))),
     *     @OA\Response(response=200, description="Supplier blacklisted.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/SupplierResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Blacklist a supplier with a mandatory documented reason.
     *
     * Requirements: 7.4, 7.5
     */
    public function blacklist(BlacklistSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $supplier = $this->service->blacklist(
                supplier:   $supplier,
                actor:      $actor,
                reason:     $request->input('reason'),
                ipAddress:  $request->ip(),
                requestId:  $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new SupplierResource($supplier),
            message: 'Supplier has been blacklisted.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/suppliers/{supplier}/performance
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(
     *     path="/suppliers/{supplier}/performance",
     *     operationId="supplierPerformance",
     *     tags={"Suppliers"},
     *     summary="Get supplier performance metrics",
     *     description="Returns aggregate on-time delivery rate, quality acceptance rate, and paginated performance history.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Performance metrics.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="summary", type="object", @OA\Property(property="on_time_delivery_rate", type="string", example="95.50"), @OA\Property(property="quality_acceptance_rate", type="string", example="98.20")), @OA\Property(property="records", type="array", @OA\Items(type="object"))), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta")))
     * )
     *
     * Return a paginated list of performance metric observations for a supplier.
     *
     * Requirements: 7.6, 7.7
     */
    public function performance(Request $request, Supplier $supplier): JsonResponse
    {
        $perPage   = min((int) $request->query('per_page', 20), 100);
        $paginator = $this->service->getPerformance($supplier, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data: [
                'summary' => [
                    'on_time_delivery_rate'   => number_format((float) $supplier->on_time_delivery_rate, 2, '.', ''),
                    'quality_acceptance_rate' => number_format((float) $supplier->quality_acceptance_rate, 2, '.', ''),
                ],
                'records' => SupplierPerformanceResource::collection($paginator->items()),
            ],
            message: 'Supplier performance metrics retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/suppliers/{supplier}/documents
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/suppliers/{supplier}/documents",
     *     operationId="uploadSupplierDocument",
     *     tags={"Suppliers"},
     *     summary="Upload compliance document for supplier",
     *     description="Accepted types: tin_certificate, vat_certificate, business_license, performance_bond, other. Max 10 MB. Automatically versions the document for the given type.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(required={"file","document_type"}, @OA\Property(property="file", type="string", format="binary"), @OA\Property(property="document_type", type="string", enum={"tin_certificate","vat_certificate","business_license","performance_bond","other"}), @OA\Property(property="expires_at", type="string", format="date", nullable=true)))),
     *     @OA\Response(response=201, description="Document uploaded.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid file type or size.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Upload a compliance document for a supplier.
     *
     * Requirements: 7.10
     */
    public function uploadDocument(UploadSupplierDocumentRequest $request, Supplier $supplier): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $document = $this->service->uploadDocument(
                supplier:     $supplier,
                file:         $request->file('file'),
                documentType: $request->input('document_type'),
                expiresAt:    $request->input('expires_at'),
                uploader:     $actor,
                ipAddress:    $request->ip(),
                requestId:    $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new SupplierDocumentResource($document),
            message: 'Compliance document uploaded successfully.',
        );
    }
}
