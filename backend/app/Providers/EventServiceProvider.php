<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        \App\Events\PurchaseRequestSubmitted::class => [
            \App\Listeners\TriggerApprovalWorkflow::class,
            \App\Listeners\SendPurchaseRequestNotification::class,
        ],
        \App\Events\PurchaseRequestApproved::class => [
            \App\Listeners\SendPurchaseRequestNotification::class,
        ],
        \App\Events\TenderPublished::class => [
            \App\Listeners\NotifySupplierOnTenderPublished::class,
        ],
        \App\Events\BidSubmitted::class => [
            \App\Listeners\SendPurchaseRequestNotification::class,
        ],
        \App\Events\PurchaseOrderIssued::class => [
            \App\Listeners\SendPurchaseRequestNotification::class,
        ],
        \App\Events\InvoiceSubmitted::class => [
            \App\Listeners\SendPurchaseRequestNotification::class,
        ],
        \App\Events\PaymentProcessed::class => [
            \App\Listeners\SendPurchaseRequestNotification::class,
        ],
        \App\Events\BudgetThresholdReached::class => [
            \App\Listeners\SendPurchaseRequestNotification::class,
        ],
    ];

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
