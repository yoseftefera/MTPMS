<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Tenants", description="System_Admin — tenant registration, status management, and analytics.")
 *
 * TenantController — System_Admin management of tenants.
 *
 * Provides CRUD for tenants and status management.
 * All endpoints require the System_Admin role (audit_logs.view permission).
 *
 * Requirements: 1.6, 1.8, 1.10
 */
class TenantController extends Controller
{
    /**
     * @OA\Get(path="/tenants", operationId="listTenants", tags={"Tenants"}, summary="List tenants (System_Admin)",
     *     description="Returns all tenants. Requires System_Admin role.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"active","suspended","deactivated"})),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Tenants list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TenantResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=403, description="Forbidden — System_Admin only.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success([], 'Tenant listing not yet implemented.');
    }

    /**
     * @OA\Post(path="/tenants", operationId="createTenant", tags={"Tenants"}, summary="Register new tenant (System_Admin)",
     *     description="Provisions a new tenant with default roles, permissions, and configuration. Requires System_Admin role.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"name","subdomain","admin_email","tenant_code"}, @OA\Property(property="name", type="string", example="Acme Corporation"), @OA\Property(property="subdomain", type="string", example="acme"), @OA\Property(property="admin_email", type="string", format="email", example="admin@acme.com"), @OA\Property(property="tenant_code", type="string", example="ACME"))),
     *     @OA\Response(response=201, description="Tenant registered.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenantResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        return $this->success([], 'Tenant creation not yet implemented.', 201);
    }

    /**
     * @OA\Get(path="/tenants/{tenant}", operationId="showTenant", tags={"Tenants"}, summary="Get tenant (System_Admin)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tenant", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Tenant returned.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenantResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(string $tenant): JsonResponse
    {
        return $this->success([], 'Tenant detail not yet implemented.');
    }

    /**
     * @OA\Put(path="/tenants/{tenant}", operationId="updateTenant", tags={"Tenants"}, summary="Update tenant (System_Admin)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tenant", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="admin_email", type="string", format="email"))),
     *     @OA\Response(response=200, description="Tenant updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenantResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null)))
     * )
     */
    public function update(Request $request, string $tenant): JsonResponse
    {
        return $this->success([], 'Tenant update not yet implemented.');
    }

    /**
     * @OA\Delete(path="/tenants/{tenant}", operationId="deleteTenant", tags={"Tenants"}, summary="Delete tenant (System_Admin)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tenant", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=204, description="Tenant deleted.", ),
     *     @OA\Response(response=403, description="Forbidden.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(string $tenant): JsonResponse
    {
        return $this->success([], 'Tenant deletion not yet implemented.');
    }

    /**
     * @OA\Patch(path="/tenants/{tenant}/status", operationId="updateTenantStatus", tags={"Tenants"}, summary="Update tenant status (System_Admin)",
     *     description="Activates, suspends, or deactivates a tenant. Status change recorded in audit log.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tenant", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"status"}, @OA\Property(property="status", type="string", enum={"active","suspended","deactivated"}))),
     *     @OA\Response(response=200, description="Status updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenantResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function updateStatus(Request $request, string $tenant): JsonResponse
    {
        return $this->success([], 'Tenant status update not yet implemented.');
    }
}
