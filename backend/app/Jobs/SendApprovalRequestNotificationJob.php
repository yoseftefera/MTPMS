<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that creates in-app Notification records for approvers
 * when a document arrives at their approval level.
 *
 * Dispatched on the 'notifications' queue.
 *
 * Requirements: 6.2
 */
class SendApprovalRequestNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 90];

    public function __construct(
        private readonly string $tenantId,
        private readonly string $documentType,
        private readonly string $documentId,
        private readonly int    $levelOrder,
        private readonly array  $approverIds,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $label   = $this->documentTypeLabel();
        $title   = "Approval required: {$label}";
        $message = "A {$label} (ID: {$this->documentId}) requires your approval at level {$this->levelOrder}.";

        foreach ($this->approverIds as $approverId) {
            try {
                Notification::withoutGlobalScopes()->create([
                    'tenant_id'  => $this->tenantId,
                    'user_id'    => $approverId,
                    'event_type' => 'approval_requested',
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => [
                        'document_type' => $this->documentType,
                        'document_id'   => $this->documentId,
                        'level_order'   => $this->levelOrder,
                    ],
                    'is_read'    => false,
                ]);
            } catch (\Throwable $e) {
                Log::error('SendApprovalRequestNotificationJob: failed to create notification', [
                    'document_id' => $this->documentId,
                    'approver_id' => $approverId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    private function documentTypeLabel(): string
    {
        return match ($this->documentType) {
            'purchase_request' => 'Purchase Request',
            'tender'           => 'Tender',
            'purchase_order'   => 'Purchase Order',
            'contract'         => 'Contract',
            'invoice'          => 'Invoice',
            default            => ucwords(str_replace('_', ' ', $this->documentType)),
        };
    }
}
