<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a procurement document completes all approval levels
 * and is fully approved.
 *
 * Listeners may use document_type to dispatch type-specific follow-up
 * actions (e.g. PurchaseRequestApproved, TenderApproved, etc.).
 *
 * Requirements: 6.3
 */
class DocumentApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $documentType  One of: purchase_request, tender, purchase_order, contract, invoice
     * @param  string  $documentId    UUID of the approved document
     * @param  string  $tenantId      UUID of the owning tenant
     */
    public function __construct(
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $tenantId,
    ) {}
}
