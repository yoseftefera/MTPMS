<?php

namespace App\Events;

use App\Models\PurchaseRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a Purchase Request is submitted for approval (status → pending_approval)
 * or when a new draft PR is created and first submitted.
 *
 * Listeners:
 *  - TriggerApprovalWorkflow  — starts the configured approval workflow
 *  - SendPurchaseRequestNotification — notifies relevant stakeholders
 *
 * Requirements: 5.6
 */
class PurchaseRequestSubmitted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly PurchaseRequest $purchaseRequest,
    ) {}
}
