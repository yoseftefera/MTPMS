<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RBAC\AssignRoleRequest;
use App\Models\User;
use App\Services\RBACService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(name="RBAC", description="Role assignment and revocation for tenant users (Tenant_Admin only).")
 *
 * RBACController — role assignment and revocation for tenant users.
 *
 * Endpoints:
 *   POST   /api/v1/users/{user}/roles          — assign a role (Tenant_Admin only)
 *   DELETE /api/v1/users/{user}/roles/{role}   — revoke a role (Tenant_Admin only)
 *
 * Business rules:
 *   - Only Tenant_Admin can assign/revoke roles (enforced via `roles.assign` permission).
 *   - System_Admin role cannot be assigned through this interface (Req 3.5).
 *   - Permission cache is invalidated within 5 seconds on every role change (Req 3.6).
 *   - Every assignment/revocation is recorded in the audit log (Req 3.9).
 *
 * Requirements: 3.2, 3.3, 3.5, 3.6, 3.9
 */
class RBACController extends Controller
{
    public function __construct(private readonly RBACService $rbacService)
    {
    }

    /**
     * POST /api/v1/users/{user}/roles
     *
     * Assign a role to a user within the current tenant.
     * See UserController::assignRole for full OpenAPI documentation.
     *
     * Requirements: 3.3, 3.5, 3.6, 3.9
     */
    public function assignRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        $roleName = $request->validated()['role'];
        $actor    = Auth::guard('api')->user();

        $result = $this->rbacService->assignRole(
            user:       $user,
            roleName:   $roleName,
            actor:      $actor,
            ipAddress:  $request->ip() ?? '0.0.0.0',
            requestId:  $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
                'errors'  => [
                    'role' => [$result['message']],
                ],
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data'],
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ]);
    }

    /**
     * DELETE /api/v1/users/{user}/roles/{role}
     *
     * Revoke a role from a user within the current tenant.
     * See UserController::revokeRole for full OpenAPI documentation.
     *
     * Requirements: 3.3, 3.5, 3.6, 3.9
     */
    public function revokeRole(Request $request, User $user, string $role): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->rbacService->revokeRole(
            user:       $user,
            roleName:   $role,
            actor:      $actor,
            ipAddress:  $request->ip() ?? '0.0.0.0',
            requestId:  $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $result['message'],
                'errors'  => [
                    'role' => [$result['message']],
                ],
                'meta'    => null,
            ], $result['code']);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data'],
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ]);
    }
}
