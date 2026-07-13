<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\WriteAuditLogJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * @OA\Tag(name="Files", description="Tenant-scoped file download and soft-delete.")
 *
 * FileController — tenant-scoped file download and soft-delete.
 *
 * Endpoints:
 *   GET    /api/v1/files/download?path={base64EncodedPath}  — stream file to client
 *   DELETE /api/v1/files/{file}                             — soft-delete (audit log only)
 *
 * Tenant isolation is enforced by verifying that the first segment of the
 * decoded storage path matches the authenticated user's tenant_id.
 *
 * Requirements: 23.5, 23.7, 23.8, 23.10
 */
class FileController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/files/download?path={base64EncodedPath}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/files/download", operationId="downloadFile", tags={"Files"}, summary="Download a stored file",
     *     description="Streams a file to the authenticated client. The path parameter must be a base64-encoded storage path. Tenant isolation enforced.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="path", in="query", required=true, description="Base64-encoded storage path of the file.", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="File stream.", @OA\MediaType(mediaType="application/octet-stream", @OA\Schema(type="string", format="binary"))),
     *     @OA\Response(response=400, description="Invalid or missing path.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=403, description="Tenant mismatch.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="File not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Stream a stored file to the authenticated client.
     *
     * The `path` query parameter must be a base64-encoded storage path as
     * returned by FileManagementService::upload().  The decoded path's first
     * segment (tenant_id) must match the authenticated user's tenant_id to
     * prevent cross-tenant access (Requirement 23.7, 23.8).
     *
     * Returns:
     *   200  — file stream with Content-Disposition: attachment
     *   400  — missing or undecodable path parameter
     *   403  — tenant mismatch
     *   404  — file not found on storage disk
     *
     * Requirements: 23.5, 23.7, 23.8, 23.10
     */
    public function download(Request $request): Response|JsonResponse
    {
        $encoded = $request->query('path');

        if (! $encoded) {
            return $this->error('The path query parameter is required.', 400);
        }

        // Decode — base64_decode returns false on invalid input
        $path = base64_decode((string) $encoded, strict: false);
        if ($path === false || $path === '') {
            return $this->error('The path query parameter could not be decoded.', 400);
        }

        // Tenant isolation: first segment of the storage path must match the
        // authenticated user's tenant_id (Requirement 23.7, 23.8).
        $user     = Auth::guard('api')->user();
        $segments = explode('/', $path);
        $fileTenant = $segments[0] ?? null;

        if (! $fileTenant || $fileTenant !== (string) $user->tenant_id) {
            return $this->error('Access denied: you do not have permission to download this file.', 403);
        }

        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($path)) {
            return $this->error('The requested file was not found.', 404);
        }

        // Derive a clean filename from the last path segment for the
        // Content-Disposition header.
        $filename = basename($path);

        // Write async audit log (Requirement 23.10)
        WriteAuditLogJob::dispatch(
            tenantId:   $user->tenant_id,
            userId:     $user->id,
            userRole:   $user->getRoleNames()->first() ?? 'unknown',
            actionType: 'file.downloaded',
            entityType: 'file',
            entityId:   null,
            before:     null,
            after:      ['path' => $path],
            ipAddress:  $request->ip() ?? '0.0.0.0',
            requestId:  $request->header('X-Request-ID'),
        )->onQueue('default');

        // Stream the file via Storage::response() which handles ETag, Last-Modified,
        // and range requests automatically and works with both local and S3 disks.
        return $disk->response($path, $filename, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/files/{file}
    // -------------------------------------------------------------------------

    /**
     * Soft-delete a file: record the deletion in the audit log without
     * physically removing the file from storage (append-only audit trail).
     *
     * The `{file}` route segment is a base64url-encoded storage path.
     * Tenant isolation is enforced identically to the download endpoint.
     *
     * Returns:
     *   204  — deletion recorded
     *   400  — undecodable path
     *   403  — tenant mismatch
     *
     * Requirements: 23.6, 23.7, 23.9
     */
    public function destroy(Request $request, string $file): Response|JsonResponse
    {
        $path = base64_decode((string) $file, strict: false);
        if ($path === false || $path === '') {
            return $this->error('The file identifier could not be decoded.', 400);
        }

        $user     = Auth::guard('api')->user();
        $segments = explode('/', $path);
        $fileTenant = $segments[0] ?? null;

        if (! $fileTenant || $fileTenant !== (string) $user->tenant_id) {
            return $this->error('Access denied: you do not have permission to delete this file.', 403);
        }

        WriteAuditLogJob::dispatch(
            tenantId:   $user->tenant_id,
            userId:     $user->id,
            userRole:   $user->getRoleNames()->first() ?? 'unknown',
            actionType: 'file.deleted',
            entityType: 'file',
            entityId:   null,
            before:     ['path' => $path],
            after:      ['deleted' => true, 'physical_removal' => false],
            ipAddress:  $request->ip() ?? '0.0.0.0',
            requestId:  $request->header('X-Request-ID'),
        )->onQueue('default');

        return $this->noContent();
    }
}
