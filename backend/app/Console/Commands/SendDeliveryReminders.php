<?php

namespace App\Console\Commands;

use App\Models\GoodsReceipt;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SendDeliveryReminders — Artisan command that notifies suppliers of upcoming
 * PO delivery deadlines.
 *
 * Registered in routes/console.php as 'purchase-orders:send-delivery-reminders'
 * and runs daily at 07:00 UTC.
 *
 * For each accepted PO whose required_delivery_date falls exactly 7 or 1 day(s)
 * from today and has no confirmed Goods_Receipt (status = 'accepted'), this
 * command creates an in-app delivery_reminder notification for the linked
 * supplier's portal user.
 *
 * Requirements: 10.6
 */
class SendDeliveryReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase-orders:send-delivery-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send 7-day and 1-day delivery reminder notifications to suppliers for upcoming PO delivery dates';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();

        $this->info("Running delivery reminders for {$today->toDateString()}...");

        $notificationsSent = 0;

        foreach ([7, 1] as $daysAhead) {
            $targetDate = $today->copy()->addDays($daysAhead);

            $this->info("  Checking for POs due on {$targetDate->toDateString()} ({$daysAhead}-day reminder)...");

            // Find all accepted POs with the target delivery date and no confirmed GRN.
            // Use withoutGlobalScopes to iterate across all tenants.
            $pos = PurchaseOrder::withoutGlobalScopes()
                ->with(['supplier.user'])
                ->where('status', 'accepted')
                ->whereDate('required_delivery_date', $targetDate->toDateString())
                ->whereDoesntHave('goodsReceipts', function ($q) {
                    $q->withoutGlobalScopes()->where('status', 'accepted');
                })
                ->get();

            if ($pos->isEmpty()) {
                $this->info("    No POs found for {$daysAhead}-day reminder.");
                continue;
            }

            $this->info("    Found {$pos->count()} PO(s). Sending notifications...");

            foreach ($pos as $po) {
                $supplier = $po->supplier;

                if (! $supplier) {
                    Log::warning('SendDeliveryReminders: supplier not found for PO', [
                        'po_id'     => $po->id,
                        'po_number' => $po->po_number,
                    ]);
                    continue;
                }

                // Only notify if the supplier has a linked portal user account.
                if (! $supplier->user_id) {
                    Log::info('SendDeliveryReminders: supplier has no portal user, skipping notification', [
                        'supplier_id' => $supplier->id,
                        'po_number'   => $po->po_number,
                    ]);
                    continue;
                }

                $deliveryDateLabel = Carbon::parse($po->required_delivery_date)->toFormattedDayDateString();

                $title   = "Delivery reminder: PO {$po->po_number} due in {$daysAhead} day(s)";
                $message = "Purchase Order {$po->po_number} requires delivery by {$deliveryDateLabel}. "
                         . "Please ensure goods are dispatched promptly to meet the delivery obligation.";

                $this->createNotification(
                    tenantId:  $po->tenant_id,
                    userId:    $supplier->user_id,
                    eventType: 'delivery_reminder',
                    title:     $title,
                    message:   $message,
                    data: [
                        'po_id'                  => $po->id,
                        'po_number'              => $po->po_number,
                        'supplier_id'            => $supplier->id,
                        'required_delivery_date' => $po->required_delivery_date instanceof Carbon
                            ? $po->required_delivery_date->toDateString()
                            : (string) $po->required_delivery_date,
                        'days_until_due'         => $daysAhead,
                    ],
                );

                $notificationsSent++;

                $this->line("    Notified supplier '{$supplier->organization_name}' for PO {$po->po_number} ({$daysAhead}-day reminder).");
            }
        }

        $this->info("Done. Sent {$notificationsSent} delivery reminder notification(s).");

        return Command::SUCCESS;
    }

    /**
     * Persist a single in-app notification record.
     */
    private function createNotification(
        string $tenantId,
        string $userId,
        string $eventType,
        string $title,
        string $message,
        array  $data,
    ): void {
        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $tenantId,
                'user_id'    => $userId,
                'event_type' => $eventType,
                'title'      => $title,
                'message'    => $message,
                'data'       => $data,
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendDeliveryReminders: failed to create notification', [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
