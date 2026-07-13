<?php

namespace App\Services;

use App\Jobs\WriteAuditLogJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * FileManagementService — centralised, tenant-aware file storage.
 *
 * All uploads are scoped to a tenant and entity so that the storage path
 * embeds tenant isolation: {tenantId}/{entityType}/{uuid}.{ext}
 *
 * Validation rules (Requirement 2.11):
 *  - Allowed MIME types: PDF, Word (doc/docx), Excel (xlsx), PNG, JPEG
 *  - Maximum size: 10 MB (10 * 1024 * 1024 bytes)
 *
 * Deletion is append-only (audit-log only, no physical removal) to preserve
 * the audit trail and support compliance requirements.
 *
 * Requirements: 2.11, 23.1–23.10
 */
class FileManagementService
{
    /**
     * Allowed MIME types for uploads.
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/png',
        'image/jpeg',
    ];

    /**
     * Maximum file size in bytes — 10 MB.
     */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    // =========================================================================
    // Upload
    // =========================================================================

    /**
     * Validate, store, and audit-log an uploaded file.
     *
     * Storage path: {tenantId}/{entityType}/{uuid4}.{extension}
     *
     * The file is written via the default filesystem disk so the same code
     * works with both the local disk (development/testing) and S3 (production)
     * without modification — only the FILESYSTEM_DISK env variable changes.
     *
     * Requirements: 2.11, 23.1, 23.2, 23.3, 23.4, 23.5
     *
     * @param  UploadedFile  $file          The validated uploaded file.
     * @param  string        $entityType    Logical entity type (e.g. 'purchase_request', 'supplier').
     * @param  string        $entityId      UUID of the owning entity for audit purposes.
     * @param  string        $tenantId      UUID of the active tenant — first path segment.
     * @param  User          $actor         Authenticated user performing the upload.
     *
     * @return array{path: string, original_name: string, size: int, mime_type: string}
     *
     * @throws InvalidArgumentException  when the file's MIME type is not allowed.
     * @throws InvalidArgumentException  when the file size exceeds 10 MB.
     */
    public function upload(
        UploadedFile $file,
        string $entityType,
        string $entityId,
        string $tenantId,
        User $actor,
    ): array {
        $this->validateFile($file);

        $extension = strtolower(
            $file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin')
        );
        $uuid      = Str::uuid()->toString();
        $path      = "{$tenantId}/{$entityType}/{$uuid}.{$extension}";

        Storage::disk(config('filesystems.default'))->put($path, $file->getContent());

        $result = [
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'size'          => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
        ];

        WriteAuditLogJob::dispatch(
            tenantId:   $tenantId,
            userId:     $actor->id,
            userRole:   $actor->getRoleNames()->first() ?? 'unknown',
            actionType: 'file.uploaded',
            entityType: $entityType,
            entityId:   $entityId,
            before:     null,
            after:      [
                'path'          => $path,
                'original_name' => $result['original_name'],
                'size'          => $result['size'],
                'mime_type'     => $result['mime_type'],
            ],
            ipAddress:  request()->ip() ?? '0.0.0.0',
            requestId:  request()->header('X-Request-ID'),
        )->onQueue('default');

        return $result;
    }

    // =========================================================================
    // Soft Delete (append-only audit log)
    // =========================================================================

    /**
     * Record a logical file deletion in the audit log.
     *
     * The physical file is intentionally NOT removed — the platform's audit
     * requirements mandate an append-only record. The caller is responsible
     * for updating any database record that references the file path.
     *
     * Requirements: 23.6, 23.7
     *
     * @param  string  $filePath  Relative storage path as returned by upload().
     * @param  User    $actor     Authenticated user performing the deletion.
     */
    public function softDelete(string $filePath, User $actor): void
    {
        // Derive tenantId from the first path segment for audit scoping.
        $tenantId = explode('/', $filePath)[0] ?? null;

        WriteAuditLogJob::dispatch(
            tenantId:   $tenantId,
            userId:     $actor->id,
            userRole:   $actor->getRoleNames()->first() ?? 'unknown',
            actionType: 'file.deleted',
            entityType: 'file',
            entityId:   null,
            before:     ['path' => $filePath],
            after:      ['deleted' => true, 'physical_removal' => false],
            ipAddress:  request()->ip() ?? '0.0.0.0',
            requestId:  request()->header('X-Request-ID'),
        )->onQueue('default');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Assert that the uploaded file's MIME type is allowed and its size is
     * within the 10 MB limit.
     *
     * @throws InvalidArgumentException
     */
    private function validateFile(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException(
                'File exceeds the maximum allowed size of 10 MB.'
            );
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException(
                "File type '{$mime}' is not allowed. "
                . 'Allowed types: PDF, Word (.doc/.docx), Excel (.xlsx), PNG, JPEG.'
            );
        }
    }
}
