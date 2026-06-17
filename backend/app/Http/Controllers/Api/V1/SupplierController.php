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
 * SupplierController — thin HTTP layer for supplier lifecycle management.
 *
 * Public endpoint (no auth):
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
     * Return a paginated, filterable list of suppliers for the active tenant.
     *
     * Query parameters:
     *   status            — filter by status value
     *   business_category — partial match
     *   search            — partial match on organization_name / contact_name / contact_email
     *   per_page          — max 100, default 20
     *
     * Roles: Procurement_Officer, Tenant_Admin (via suppliers.view permission)
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
     * Update a supplier's profile fields.
     *
     * Allowed fields: organization_name, contact_name, contact_email, contact_phone, business_category
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
     * Approve a pending supplier registration (pending_verification → active).
     *
     * Roles: Procurement_Officer
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
     * Reject a pending supplier registration (pending_verification → inactive).
     *
     * Roles: Procurement_Officer
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
     * Blacklist a supplier with a mandatory documented reason.
     *
     * A blacklisted supplier cannot submit bids or receive purchase orders.
     * The action, reason, and actor are recorded in the Audit_Log.
     *
     * Roles: Procurement_Officer
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
     * Return a paginated list of performance metric observations for a supplier.
     *
     * Also includes the aggregate rates (on_time_delivery_rate and
     * quality_acceptance_rate) from the supplier record itself.
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
     * Upload a compliance document for a supplier.
     *
     * Automatically increments the version number for the given document_type.
     * Returns HTTP 201 with the new document record.
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
