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
 * Queued job that notifies the document originator (and optionally other
 * stakeholders) of a final approval outcome: approved, rejected, or
 * returned for revision.
 *
 * Dispatched on the 'notifications' queue.
 *
 * Requirements: 5.9, 6.4, 6.5
 */
class SendApprovalOutcomeNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 90];

    /**
     * @param  string  $outcome  One of: approved, rejected, revision_required
     * @param  array   $recipientIds  UUIDs of users to notify
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $documentType,
        private readonly string $documentId,
        private readonly string $outcome,
        private readonly string $comment,
        private readonly array  $recipientIds,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $label   = $this->documentTypeLabel();
        [$title, $message] = $this->buildMessage($label);

        foreach ($this->recipientIds as $recipientId) {
            try {
                Notification::withoutGlobalScopes()->create([
                    'tenant_id'  => $this->tenantId,
                    'user_id'    => $recipientId,
                    'event_type' => "document_{$this->outcome}",
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => [
                        'document_type' => $this->documentType,
                        'document_id'   => $this->documentId,
                        'outcome'       => $this->outcome,
                        'comment'       => $this->comment,
                    ],
                    'is_read'    => false,
                ]);
            } catch (\Throwable $e) {
                Log::error('SendApprovalOutcomeNotificationJob: failed to create notification', [
                    'document_id'  => $this->documentId,
                    'recipient_id' => $recipientId,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function buildMessage(string $label): array
    {
        return match ($this->outcome) {
            'approved' => [
                "{$label} approved",
                "Your {$label} (ID: {$this->documentId}) has been fully approved.",
            ],
            'rejected' => [
                "{$label} rejected",
                "Your {$label} (ID: {$this->documentId}) has been rejected. Reason: {$this->comment}",
            ],
            'revision_required' => [
                "{$label} returned for revision",
                "Your {$label} (ID: {$this->documentId}) has been returned for revision. Comments: {$this->comment}",
            ],
            default => [
                "{$label} status updated",
                "Your {$label} (ID: {$this->documentId}) status changed to {$this->outcome}.",
            ],
        };
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
