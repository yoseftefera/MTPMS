<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an approver rejects a procurement document.
 *
 * Requirements: 6.4
 */
class DocumentRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $rejectionReason,
        public readonly string $rejectedByUserId,
    ) {}
}
