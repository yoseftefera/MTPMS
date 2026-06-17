<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an approver returns a procurement document for revision.
 *
 * Requirements: 6.5
 */
class DocumentReturnedForRevision
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $revisionComments,
        public readonly string $returnedByUserId,
    ) {}
}
