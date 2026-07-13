<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GenerateReportJob — asynchronous report generation for large datasets (>10,000 rows).
 *
 * Queue   : reports
 * Tries   : 3
 * Backoff : 30 s → 90 s → 270 s  (exponential)
 *
 * On success the generated file is stored at a tenant-scoped path:
 *   reports/{tenant_id}/{report_type}_{timestamp}_{uuid}.{ext}
 *
 * A Notification record is created for the requesting user so they can download
 * the file via the platform's existing notification + file download flow.
 *
 * Requirements: 16.7, 16.8
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Maximum number of attempts before the job is failed. */
    public int $tries = 3;

    /**
     * Retry delays in seconds between attempts.
     * attempt 1 → wait 30 s → attempt 2 → wait 90 s → attempt 3 → wait 270 s → fail
     *
     * @var array<int>
     */
    public array $backoff = [30, 90, 270];

    /**
     * @param  string  $reportType  One of: dashboard, procurement_timeline, spending_analytics,
     *                              supplier_performance, tender_statistics, financial_summary
     * @param  string  $format      'pdf' | 'excel'
     * @param  array   $filters     Arbitrary filter map passed through to the report data query.
     * @param  string  $userId      UUID of the requesting user.
     * @param  string  $tenantId    UUID of the requesting tenant.
     */
    public function __construct(
        private readonly string $reportType,
        private readonly string $format,
        private readonly array  $filters,
        private readonly string $userId,
        private readonly string $tenantId,
    ) {
        $this->onQueue('reports');
    }

    // -------------------------------------------------------------------------
    // Execute
    // -------------------------------------------------------------------------

    /**
     * Generate the report file and notify the user when ready.
     *
     * Requirements: 16.7, 16.8
     */
    public function handle(ExportService $exportService): void
    {
        $user = User::withoutGlobalScopes()->findOrFail($this->userId);

        // Generate file content
        [$fileContent, $mimeType, $extension] = $exportService->generate(
            reportType: $this->reportType,
            format:     $this->format,
            filters:    $this->filters,
            user:       $user,
        );

        // Build a tenant-scoped storage path
        $timestamp  = now()->format('Ymd_His');
        $uniqueId   = Str::uuid()->toString();
        $fileName   = "{$this->reportType}_{$timestamp}_{$uniqueId}.{$extension}";
        $storagePath = "reports/{$this->tenantId}/{$fileName}";

        Storage::disk('local')->put($storagePath, $fileContent);

        Log::info('GenerateReportJob: file stored', [
            'path'        => $storagePath,
            'report_type' => $this->reportType,
            'format'      => $this->format,
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
        ]);

        // Notify the user that their report is ready
        $this->notifyUser($storagePath, $fileName);
    }

    // -------------------------------------------------------------------------
    // Final failure handler
    // -------------------------------------------------------------------------

    /**
     * Called after all retry attempts are exhausted.
     *
     * Notifies the requesting user that report generation failed so they
     * can retry manually instead of waiting indefinitely.
     *
     * Requirements: 16.8
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateReportJob: permanently failed after all retries', [
            'report_type' => $this->reportType,
            'format'      => $this->format,
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'error'       => $exception->getMessage(),
        ]);

        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $this->tenantId,
                'user_id'    => $this->userId,
                'event_type' => 'report_generation_failed',
                'title'      => 'Report generation failed',
                'message'    => "Your {$this->reportType} report ({$this->format}) could not be generated after 3 attempts. "
                              . "Error: {$exception->getMessage()}",
                'data'       => [
                    'report_type' => $this->reportType,
                    'format'      => $this->format,
                    'filters'     => $this->filters,
                    'error'       => $exception->getMessage(),
                ],
                'is_read'    => false,
                'read_at'    => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $inner) {
            Log::critical('GenerateReportJob: could not create failure notification', [
                'user_id' => $this->userId,
                'error'   => $inner->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Create a Notification record informing the user their report is ready.
     *
     * The `data` payload contains `storage_path` which the download endpoint
     * can use to serve the file after verifying tenant ownership.
     */
    private function notifyUser(string $storagePath, string $fileName): void
    {
        $label = str_replace('_', ' ', ucfirst($this->reportType));

        Notification::withoutGlobalScopes()->create([
            'tenant_id'  => $this->tenantId,
            'user_id'    => $this->userId,
            'event_type' => 'report_ready',
            'title'      => 'Your report is ready',
            'message'    => "Your {$label} report ({$this->format}) has been generated and is ready for download.",
            'data'       => [
                'report_type'  => $this->reportType,
                'format'       => $this->format,
                'filters'      => $this->filters,
                'storage_path' => $storagePath,
                'file_name'    => $fileName,
                'generated_at' => now()->toIso8601String(),
            ],
            'is_read'    => false,
            'read_at'    => null,
            'created_at' => now(),
        ]);
    }
}
