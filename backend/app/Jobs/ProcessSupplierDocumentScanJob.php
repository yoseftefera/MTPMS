<?php

namespace App\Jobs;

use App\Models\SupplierDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * ProcessSupplierDocumentScanJob — async virus scan integration point.
 *
 * Dispatched after a supplier compliance document is uploaded.  When the
 * SUPPLIER_DOCUMENT_SCAN_ENABLED environment variable is set to true this job
 * invokes the configured AV scan service.  In stub / development mode it always
 * resolves the document as clean so that the rest of the platform is unaffected.
 *
 * Outcomes:
 *   clean    — document is safe; scan_status set to 'clean'.
 *   infected — document is flagged; scan_status set to 'infected'; audit event
 *              dispatched so the Procurement_Officer is notified.
 *
 * The scan_status column must exist on the supplier_documents table.  If the
 * SupplierDocument record cannot be found the job is released with a short
 * delay to handle eventual-consistency race conditions (e.g. the row is still
 * being inserted).
 *
 * Requirements: 23.3, 23.5, 23.8, 2.11
 */
class ProcessSupplierDocumentScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before re-attempting a failed job.
     */
    public int $backoff = 30;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param  string  $supplierDocumentId  UUID of the SupplierDocument record.
     * @param  string  $filePath            Relative storage path of the uploaded file.
     */
    public function __construct(
        private readonly string $supplierDocumentId,
        private readonly string $filePath,
    ) {
        $this->onQueue('default');
    }

    // -------------------------------------------------------------------------
    // Handle
    // -------------------------------------------------------------------------

    /**
     * Execute the scan, update scan_status, and write an audit log entry if the
     * file is infected.
     *
     * Requirements: 23.3, 23.5, 23.8
     */
    public function handle(): void
    {
        $document = SupplierDocument::find($this->supplierDocumentId);

        if (! $document) {
            // Record not yet visible — release and retry after a short delay.
            $this->release(10);
            return;
        }

        $scanEnabled = filter_var(
            env('SUPPLIER_DOCUMENT_SCAN_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        $scanResult = $scanEnabled
            ? $this->performScan($this->filePath)
            : 'clean';   // Stub: always clean when scanning is disabled.

        // Persist scan outcome — requires scan_status column on supplier_documents.
        $document->scan_status = $scanResult;
        $document->save();

        if ($scanResult === 'infected') {
            // Audit log the infected-file event so Procurement_Officers are alerted.
            WriteAuditLogJob::dispatch(
                tenantId:   $document->tenant_id,
                userId:     null,
                userRole:   'system',
                actionType: 'file.scan_infected',
                entityType: 'supplier_document',
                entityId:   $document->id,
                before:     null,
                after:      [
                    'file_path'   => $this->filePath,
                    'scan_status' => 'infected',
                ],
                ipAddress:  '0.0.0.0',
                requestId:  null,
            )->onQueue('default');
        }
    }

    // -------------------------------------------------------------------------
    // Failure handler
    // -------------------------------------------------------------------------

    /**
     * Called after all retry attempts are exhausted.  Mark the document scan
     * status as 'scan_failed' so the UI can surface a human-reviewable state.
     */
    public function failed(?Throwable $exception): void
    {
        $document = SupplierDocument::find($this->supplierDocumentId);

        if ($document) {
            $document->scan_status = 'scan_failed';
            $document->save();
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Invoke the external AV scan service.
     *
     * This is the integration stub.  Replace the body of this method with a real
     * ClamAV / cloud AV SDK call when the scanning backend is provisioned.
     * The method must return either 'clean' or 'infected'.
     *
     * Requirements: 23.3, 23.5
     *
     * @param  string  $filePath  Relative storage path to scan.
     * @return string  'clean' | 'infected'
     */
    private function performScan(string $filePath): string
    {
        // -------------------------------------------------------------------
        // Stub implementation — returns 'clean' for all files.
        // Replace this with a real AV integration (e.g. ClamAV socket call,
        // AWS Macie, or VirusTotal API) before deploying to production.
        // -------------------------------------------------------------------
        return 'clean';
    }
}
