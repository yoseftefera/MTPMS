<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SendContractRenewalAlerts — Artisan command that sends contract renewal
 * reminder notifications based on contract end dates.
 *
 * Registered in routes/console.php as 'contracts:send-renewal-alerts'
 * and runs daily at 08:00 UTC.
 *
 * Two alert tiers:
 *  1. 60-day reminder  : find active contracts where end_date = today + 60 days
 *                        → notify the contract creator (Procurement_Officer)
 *                          and all Tenant_Admins in the same tenant.
 *
 *  2. 30-day escalation: find active contracts where end_date = today + 30 days
 *                        AND no ContractAmendment was created in the past 30 days
 *                        (proxy for "no renewal action taken")
 *                        → send escalation notification to the same recipients.
 *
 * Requirements: 11.3, 11.4
 */
class SendContractRenewalAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contracts:send-renewal-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send 60-day renewal reminders and 30-day escalation alerts for active contracts nearing their end date';

    /**
     * Execute the console command.
     *
     * Requirements: 11.3, 11.4
     */
    public function handle(): int
    {
        $today = Carbon::today();

        $this->info("Running contract renewal alerts for {$today->toDateString()}...");

        $notificationsSent = 0;

        // ── Tier 1: 60-day renewal reminder ───────────────────────────────────
        $notificationsSent += $this->send60DayReminders($today);

        // ── Tier 2: 30-day escalation ─────────────────────────────────────────
        $notificationsSent += $this->send30DayEscalations($today);

        $this->info("Done. Sent {$notificationsSent} contract renewal notification(s).");

        return Command::SUCCESS;
    }

    // =========================================================================
    // Tier 1 — 60-day reminder
    // =========================================================================

    /**
     * Find active contracts ending in exactly 60 days and notify the creator
     * and all Tenant_Admins.
     *
     * Requirements: 11.3
     */
    private function send60DayReminders(Carbon $today): int
    {
        $targetDate = $today->copy()->addDays(60);

        $this->info("  [60-day] Checking for contracts ending on {$targetDate->toDateString()}...");

        $contracts = Contract::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('status', 'active')
            ->whereDate('end_date', $targetDate->toDateString())
            ->get();

        if ($contracts->isEmpty()) {
            $this->info('    No contracts found for 60-day reminder.');
            return 0;
        }

        $this->info("    Found {$contracts->count()} contract(s). Sending 60-day reminders...");

        $notificationsSent = 0;

        foreach ($contracts as $contract) {
            $endDateLabel = Carbon::parse($contract->end_date)->toFormattedDayDateString();

            $title   = "Contract renewal reminder: {$contract->contract_number} expires in 60 days";
            $message = "Contract {$contract->contract_number} ({$contract->title}) is due to expire on "
                     . "{$endDateLabel}. Please review the contract terms and initiate the renewal process.";

            $data = [
                'contract_id'     => $contract->id,
                'contract_number' => $contract->contract_number,
                'title'           => $contract->title,
                'end_date'        => $contract->end_date instanceof Carbon
                    ? $contract->end_date->toDateString()
                    : (string) $contract->end_date,
                'days_until_expiry' => 60,
                'alert_type'      => 'renewal_reminder_60_days',
            ];

            $recipients = $this->resolveRecipients($contract);

            foreach ($recipients as $userId) {
                $this->createNotification(
                    tenantId:  $contract->tenant_id,
                    userId:    $userId,
                    eventType: 'contract_renewal_reminder',
                    title:     $title,
                    message:   $message,
                    data:      $data,
                );
                $notificationsSent++;
            }

            $recipientCount = count($recipients);
            $this->line("    Notified {$recipientCount} recipient(s) for contract {$contract->contract_number} (60-day reminder).");
        }

        return $notificationsSent;
    }

    // =========================================================================
    // Tier 2 — 30-day escalation
    // =========================================================================

    /**
     * Find active contracts ending in exactly 30 days with no renewal action
     * taken in the past 30 days and send an escalation notification.
     *
     * "No renewal action" is determined by the absence of a ContractAmendment
     * created in the last 30 days (amendments are the primary renewal signal).
     *
     * Requirements: 11.4
     */
    private function send30DayEscalations(Carbon $today): int
    {
        $targetDate     = $today->copy()->addDays(30);
        $renewalCutoff  = $today->copy()->subDays(30);

        $this->info("  [30-day] Checking for contracts ending on {$targetDate->toDateString()} with no renewal action...");

        $contracts = Contract::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('status', 'active')
            ->whereDate('end_date', $targetDate->toDateString())
            ->whereDoesntHave('amendments', function ($q) use ($renewalCutoff) {
                $q->withoutGlobalScopes()
                  ->where('created_at', '>=', $renewalCutoff->toDateTimeString());
            })
            ->get();

        if ($contracts->isEmpty()) {
            $this->info('    No contracts require 30-day escalation.');
            return 0;
        }

        $this->info("    Found {$contracts->count()} contract(s) requiring escalation. Sending 30-day escalations...");

        $notificationsSent = 0;

        foreach ($contracts as $contract) {
            $endDateLabel = Carbon::parse($contract->end_date)->toFormattedDayDateString();

            $title   = "URGENT — Contract expiry escalation: {$contract->contract_number} expires in 30 days";
            $message = "Contract {$contract->contract_number} ({$contract->title}) expires on {$endDateLabel} "
                     . "and no renewal action has been taken. Immediate action is required to avoid a lapse "
                     . "in contract coverage.";

            $data = [
                'contract_id'     => $contract->id,
                'contract_number' => $contract->contract_number,
                'title'           => $contract->title,
                'end_date'        => $contract->end_date instanceof Carbon
                    ? $contract->end_date->toDateString()
                    : (string) $contract->end_date,
                'days_until_expiry' => 30,
                'alert_type'      => 'renewal_escalation_30_days',
            ];

            $recipients = $this->resolveRecipients($contract);

            foreach ($recipients as $userId) {
                $this->createNotification(
                    tenantId:  $contract->tenant_id,
                    userId:    $userId,
                    eventType: 'contract_renewal_escalation',
                    title:     $title,
                    message:   $message,
                    data:      $data,
                );
                $notificationsSent++;
            }

            $recipientCount = count($recipients);
            $this->line("    Notified {$recipientCount} recipient(s) for contract {$contract->contract_number} (30-day escalation).");
        }

        return $notificationsSent;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the set of user IDs to notify for a contract:
     *   1. The contract creator (if they have a user account)
     *   2. All active Tenant_Admins in the same tenant
     *
     * Returns a deduplicated array of user IDs.
     */
    private function resolveRecipients(Contract $contract): array
    {
        $recipientIds = [];

        // 1. Contract creator
        if ($contract->created_by) {
            $recipientIds[] = $contract->created_by;
        }

        // 2. Active Tenant_Admins in the same tenant
        try {
            $tenantAdmins = User::withoutGlobalScopes()
                ->where('tenant_id', $contract->tenant_id)
                ->where('status', 'active')
                ->get()
                ->filter(fn (User $user) => $user->hasRole('Tenant_Admin'));

            foreach ($tenantAdmins as $admin) {
                $recipientIds[] = $admin->id;
            }
        } catch (\Throwable $e) {
            Log::warning('SendContractRenewalAlerts: failed to resolve Tenant_Admin recipients', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // Deduplicate
        return array_values(array_unique($recipientIds));
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
            Log::error('SendContractRenewalAlerts: failed to create notification', [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
