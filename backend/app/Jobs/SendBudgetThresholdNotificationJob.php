<?php

namespace App\Jobs;

use App\Models\Budget;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that persists in-app Notification records when a department
 * budget crosses the 75 % or 90 % consumption threshold.
 *
 * Dispatched on the 'notifications' queue (high-priority).
 *
 * Requirements: 13.7
 */
class SendBudgetThresholdNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Exponential backoff: 10 s, 30 s, 90 s
     */
    public array $backoff = [10, 30, 90];

    public function __construct(
        private readonly string $budgetId,
        private readonly string $tenantId,
        private readonly int    $thresholdPercent,   // 75 or 90
        private readonly string $usedAmount,
        private readonly string $totalAmount,
        private readonly string $utilizationPercent, // e.g. '76.50'
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Persist Notification records for Finance_Officer and Tenant_Admin users
     * belonging to the same tenant.
     */
    public function handle(): void
    {
        $budget = Budget::withoutGlobalScopes()
            ->with(['department'])
            ->find($this->budgetId);

        if (! $budget) {
            Log::warning('SendBudgetThresholdNotificationJob: budget not found', [
                'budget_id' => $this->budgetId,
            ]);

            return;
        }

        $departmentName = $budget->department->name ?? 'Unknown Department';
        $fiscalYear     = $budget->fiscal_year;

        $title   = "Budget threshold reached: {$this->thresholdPercent}%";
        $message = "The {$departmentName} department budget for fiscal year {$fiscalYear} "
                 . "has reached {$this->utilizationPercent}% utilization "
                 . "({$this->usedAmount} of {$this->totalAmount} {$budget->currency}).";

        // Notify all Finance_Officers and Tenant_Admins in the same tenant
        $recipients = User::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->get()
            ->filter(function (User $user) {
                return $user->hasAnyRole(['Finance_Officer', 'Tenant_Admin']);
            });

        foreach ($recipients as $recipient) {
            try {
                Notification::withoutGlobalScopes()->create([
                    'tenant_id'  => $this->tenantId,
                    'user_id'    => $recipient->id,
                    'event_type' => 'budget_threshold_reached',
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => [
                        'budget_id'           => $this->budgetId,
                        'department_name'     => $departmentName,
                        'fiscal_year'         => $fiscalYear,
                        'threshold_percent'   => $this->thresholdPercent,
                        'utilization_percent' => $this->utilizationPercent,
                        'used_amount'         => $this->usedAmount,
                        'total_amount'        => $this->totalAmount,
                        'currency'            => $budget->currency,
                    ],
                    'is_read'    => false,
                ]);
            } catch (\Throwable $e) {
                Log::error('SendBudgetThresholdNotificationJob: failed to create notification', [
                    'budget_id'    => $this->budgetId,
                    'recipient_id' => $recipient->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
    }
}
