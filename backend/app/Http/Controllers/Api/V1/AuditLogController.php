<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(name="Audit Logs", description="Immutable audit trail — read-only access, reject all modifications.")
 *
 * AuditLogController — read-only access to the immutable audit trail.
 *
 * Endpoints:
 *   GET    /api/v1/audit-logs          — paginated list with filters
 *   DELETE /api/v1/audit-logs/{id}     — always HTTP 403 (immutable)
 *   PUT    /api/v1/audit-logs/{id}     — always HTTP 403 (immutable)
 *   PATCH  /api/v1/audit-logs/{id}     — always HTTP 403 (immutable)
 *
 * Tenant scoping:
 *   - Tenant_Admin: results are restricted to their own tenant_id
 *   - System_Admin: no tenant filter applied (cross-tenant visibility)
 *
 * Query parameters for index():
 *   user_id      — filter by exact user_id
 *   action       — filter by exact action string
 *   entity_type  — filter by exact entity_type
 *   date_from    — created_at >= (Y-m-d or ISO 8601)
 *   date_to      — created_at <= (Y-m-d or ISO 8601)
 *   ip_address   — filter by exact IP address
 *   per_page     — results per page (default 20, max 100)
 *
 * Requirements: 17.6, 17.7, 17.8
 */
class AuditLogController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/audit-logs
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/audit-logs", operationId="listAuditLogs", tags={"Audit Logs"}, summary="List audit log entries",
     *     description="Returns paginated audit log entries. System_Admin sees all tenants; Tenant_Admin is scoped to own tenant.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="user_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="action", in="query", required=false, @OA\Schema(type="string"), description="Exact action string, e.g. purchase_request.submitted."),
     *     @OA\Parameter(name="entity_type", in="query", required=false, @OA\Schema(type="string"), description="Exact entity type, e.g. PurchaseRequest."),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date-time")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date-time")),
     *     @OA\Parameter(name="ip_address", in="query", required=false, @OA\Schema(type="string", example="192.168.1.1")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Audit logs returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/AuditLogResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated, filtered list of audit log entries.
     *
     * System_Admin sees all records across tenants.
     * Tenant_Admin sees only records belonging to their own tenant.
     *
     * Requirements: 17.6, 17.7
     */
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::guard('api')->user();
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = AuditLog::query()->orderByDesc('created_at');

        // --- Tenant scoping ---------------------------------------------------
        // Determine whether the caller is a System_Admin.
        $isSystemAdmin = false;
        try {
            $isSystemAdmin = $user?->hasRole('System_Admin');
        } catch (\Throwable) {
            // If role lookup fails, default to tenant-scoped for safety.
        }

        if (! $isSystemAdmin) {
            // Tenant_Admin (and all other roles): scope to own tenant.
            $query->where('tenant_id', $user?->tenant_id);
        }

        // --- Filters ----------------------------------------------------------
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('ip_address')) {
            $query->where('ip_address', $request->query('ip_address'));
        }

        $paginator = $query->paginate($perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      AuditLogResource::collection($paginator->items()),
            message:   'Audit logs retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/audit-logs/{id}
    // -------------------------------------------------------------------------

    /**
     * @OA\Delete(path="/audit-logs/{id}", operationId="deleteAuditLog", tags={"Audit Logs"}, summary="Attempt to delete audit log — always HTTP 403",
     *     description="Audit logs are immutable. This endpoint always returns HTTP 403 regardless of role.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=403, description="Immutable — deletion not permitted.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Audit logs are immutable — always reject delete attempts with HTTP 403.
     *
     * Requirements: 17.5, 17.8
     */
    public function destroy(string $id): JsonResponse
    {
        return $this->error('Audit logs are immutable and cannot be deleted.', 403);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/audit-logs/{id}  &  PATCH /api/v1/audit-logs/{id}
    // -------------------------------------------------------------------------

    /**
     * @OA\Put(path="/audit-logs/{id}", operationId="updateAuditLog", tags={"Audit Logs"}, summary="Attempt to update audit log — always HTTP 403",
     *     description="Audit logs are immutable. This endpoint always returns HTTP 403 regardless of role.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=403, description="Immutable — modification not permitted.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Audit logs are immutable — always reject update attempts with HTTP 403.
     *
     * Requirements: 17.5, 17.8
     */
    public function update(string $id): JsonResponse
    {
        return $this->error('Audit logs are immutable and cannot be modified.', 403);
    }
}
