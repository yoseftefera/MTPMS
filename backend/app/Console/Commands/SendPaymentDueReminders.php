<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SendPaymentDueReminders — Artisan command that sends payment due reminder
 * notifications to Finance_Officer users 5 days before a payment is due.
 *
 * Registered in routes/console.php as 'payments:send-due-reminders'
 * and runs daily at 09:00 UTC.
 *
 * Logic:
 *  - Find invoices where due_date = today + 5 days
 *    AND status IN (approved, partially_paid).
 *  - For each such invoice, notify all active Finance_Officer users in the
 *    same tenant.
 *
 * Requirements: 14.7
 */
class SendPaymentDueReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:send-due-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send payment due reminder notifications to Finance Officers 5 days before invoice due dates';

    /**
     * Execute the console command.
     *
     * Requirements: 14.7
     */
    public function handle(): int
    {
        $today      = Carbon::today();
        $targetDate = $today->copy()->addDays(5);

        $this->info("Running payment due reminders for due_date = {$targetDate->toDateString()}...");

        $invoices = Invoice::withoutGlobalScopes()
            ->with(['supplier'])
            ->whereIn('status', ['approved', 'partially_paid'])
            ->whereDate('due_date', $targetDate->toDateString())
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('No invoices due in 5 days. Nothing to do.');
            return Command::SUCCESS;
        }

        $this->info("Found {$invoices->count()} invoice(s) due on {$targetDate->toDateString()}.");

        $notificationsSent = 0;

        foreach ($invoices as $invoice) {
            $recipients = $this->resolveFinanceOfficers($invoice->tenant_id);

            if (empty($recipients)) {
                $this->line("  No Finance_Officer recipients found for tenant {$invoice->tenant_id}. Skipping invoice {$invoice->invoice_number}.");
                continue;
            }

            $dueDateLabel  = Carbon::parse($invoice->due_date)->toFormattedDayDateString();
            $supplierName  = $invoice->supplier?->organization_name ?? 'Unknown Supplier';
            $balanceDue    = bcsub(
                number_format((float) $invoice->total_amount, 2, '.', ''),
                number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
                2
            );

            $title   = "Payment Due in 5 Days: Invoice {$invoice->invoice_number}";
            $message = "Invoice {$invoice->invoice_number} from {$supplierName} is due on {$dueDateLabel}. "
                     . "Outstanding balance: {$invoice->currency} {$balanceDue}.";

            $data = [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'supplier_name'  => $supplierName,
                'total_amount'   => $invoice->total_amount,
                'paid_amount'    => $invoice->paid_amount,
                'balance_due'    => $balanceDue,
                'currency'       => $invoice->currency,
                'due_date'       => $invoice->due_date instanceof Carbon
                    ? $invoice->due_date->toDateString()
                    : (string) $invoice->due_date,
                'days_until_due' => 5,
            ];

            foreach ($recipients as $userId) {
                $this->createNotification(
                    tenantId:  $invoice->tenant_id,
                    userId:    $userId,
                    eventType: 'payment_due_reminder',
                    title:     $title,
                    message:   $message,
                    data:      $data,
                );
                $notificationsSent++;
            }

            $this->line("  Notified " . count($recipients) . " Finance_Officer(s) for invoice {$invoice->invoice_number}.");
        }

        $this->info("Done. Sent {$notificationsSent} payment due reminder notification(s).");

        return Command::SUCCESS;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve all active Finance_Officer user IDs for the given tenant.
     *
     * @return string[]
     */
    private function resolveFinanceOfficers(string $tenantId): array
    {
        try {
            return User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->get()
                ->filter(fn (User $user) => $user->hasRole('Finance_Officer'))
                ->pluck('id')
                ->all();
        } catch (\Throwable $e) {
            Log::warning('SendPaymentDueReminders: failed to resolve Finance_Officer recipients', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
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
            Log::error('SendPaymentDueReminders: failed to create notification', [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
