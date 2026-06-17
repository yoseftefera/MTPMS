<?php

namespace App\Console\Commands;

use App\Jobs\WriteAuditLogJob;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MarkOverduePOs — Artisan command that flags accepted POs past their
 * required delivery date (with no confirmed GRN) as `overdue` and notifies
 * the responsible Procurement_Officer.
 *
 * Registered in routes/console.php as 'purchase-orders:mark-overdue'
 * and runs every 30 minutes.
 *
 * For each qualifying PO this command:
 *  1. Transitions the PO status from `accepted` to `overdue`
 *  2. Creates an in-app notification for the Procurement_Officer who created
 *     the PO (the `created_by` user)
 *  3. Dispatches a WriteAuditLogJob recording the system-initiated status change
 *
 * Requirements: 10.7
 */
class MarkOverduePOs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase-orders:mark-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flag accepted POs past their required delivery date without a confirmed Goods Receipt as overdue and notify the Procurement Officer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();

        $this->info("Scanning for overdue POs as of {$today->toDateString()}...");

        // Find all accepted POs whose required_delivery_date is strictly before
        // today and that have no confirmed (accepted) GRN, and are not already
        // marked overdue.
        $pos = PurchaseOrder::withoutGlobalScopes()
            ->with(['supplier', 'createdBy'])
            ->where('status', 'accepted')
            ->whereDate('required_delivery_date', '<', $today->toDateString())
            ->whereDoesntHave('goodsReceipts', function ($q) {
                $q->withoutGlobalScopes()->where('status', 'accepted');
            })
            ->get();

        if ($pos->isEmpty()) {
            $this->info('No overdue POs found. Nothing to do.');
            return Command::SUCCESS;
        }

        $this->info("Found {$pos->count()} overdue PO(s). Processing...");

        $markedCount = 0;

        foreach ($pos as $po) {
            try {
                DB::transaction(function () use ($po, $today, &$markedCount) {
                    $beforeStatus = $po->status;

                    // 1. Mark the PO as overdue.
                    $po->withoutGlobalScopes();
                    PurchaseOrder::withoutGlobalScopes()
                        ->where('id', $po->id)
                        ->update(['status' => 'overdue']);

                    $deliveryDateLabel = Carbon::parse($po->required_delivery_date)->toFormattedDayDateString();
                    $daysOverdue       = (int) Carbon::parse($po->required_delivery_date)->diffInDays($today);

                    $title   = "PO {$po->po_number} is overdue";
                    $message = "Purchase Order {$po->po_number} was due for delivery on "
                             . "{$deliveryDateLabel} ({$daysOverdue} day(s) ago) "
                             . "but no confirmed Goods Receipt has been recorded. "
                             . "Please follow up with the supplier immediately.";

                    $notificationData = [
                        'po_id'                  => $po->id,
                        'po_number'              => $po->po_number,
                        'supplier_id'            => $po->supplier_id,
                        'supplier_name'          => $po->supplier?->organization_name,
                        'required_delivery_date' => $po->required_delivery_date instanceof Carbon
                            ? $po->required_delivery_date->toDateString()
                            : (string) $po->required_delivery_date,
                        'days_overdue'           => $daysOverdue,
                    ];

                    // 2. Notify the Procurement_Officer (created_by user).
                    if ($po->created_by) {
                        $this->createNotification(
                            tenantId:  $po->tenant_id,
                            userId:    $po->created_by,
                            eventType: 'po_overdue',
                            title:     $title,
                            message:   $message,
                            data:      $notificationData,
                        );
                    } else {
                        Log::warning('MarkOverduePOs: PO has no created_by user, skipping Procurement_Officer notification', [
                            'po_id'     => $po->id,
                            'po_number' => $po->po_number,
                        ]);
                    }

                    // 3. Dispatch an immutable audit log entry.
                    WriteAuditLogJob::dispatch(
                        tenantId:   $po->tenant_id,
                        userId:     null,                    // system-initiated action
                        userRole:   'system',
                        actionType: 'status_changed',
                        entityType: 'purchase_order',
                        entityId:   $po->id,
                        before:     ['status' => $beforeStatus],
                        after:      ['status' => 'overdue'],
                        ipAddress:  '127.0.0.1',
                        requestId:  null,
                    );

                    $markedCount++;

                    $this->line("  Marked PO {$po->po_number} as overdue ({$daysOverdue} day(s) past due).");
                });
            } catch (\Throwable $e) {
                Log::error('MarkOverduePOs: failed to process PO', [
                    'po_id'     => $po->id,
                    'po_number' => $po->po_number ?? 'unknown',
                    'error'     => $e->getMessage(),
                ]);

                $this->warn("  Failed to process PO {$po->po_number}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Marked {$markedCount} PO(s) as overdue.");

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
            Log::error('MarkOverduePOs: failed to create notification', [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
