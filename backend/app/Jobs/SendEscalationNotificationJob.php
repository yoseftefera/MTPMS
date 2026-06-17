<?php

namespace App\Jobs;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that notifies the escalation target (approver's supervisor or
 * Tenant_Admin) when an approval is overdue.
 *
 * Dispatched on the 'notifications' queue.
 *
 * Requirements: 6.9
 */
class SendEscalationNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 90];

    public function __construct(
        private readonly string $tenantId,
        private readonly string $documentType,
        private readonly string $documentId,
        private readonly string $approvalId,
        private readonly string $originalApproverId,
        private readonly string $escalationTargetId,
        private readonly int    $escalationHours,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $label   = $this->documentTypeLabel();
        $title   = "Escalation: overdue approval for {$label}";
        $message = "An approval for {$label} (ID: {$this->documentId}) has been pending for over "
                 . "{$this->escalationHours} hours. The assigned approver (ID: {$this->originalApproverId}) "
                 . 'has not yet acted. Please review and take action.';

        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $this->tenantId,
                'user_id'    => $this->escalationTargetId,
                'event_type' => 'approval_escalated',
                'title'      => $title,
                'message'    => $message,
                'data'       => [
                    'document_type'        => $this->documentType,
                    'document_id'          => $this->documentId,
                    'approval_id'          => $this->approvalId,
                    'original_approver_id' => $this->originalApproverId,
                    'escalation_hours'     => $this->escalationHours,
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendEscalationNotificationJob: failed to create notification', [
                'document_id'          => $this->documentId,
                'escalation_target_id' => $this->escalationTargetId,
                'error'                => $e->getMessage(),
            ]);
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
