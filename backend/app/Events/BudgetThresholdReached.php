<?php

namespace App\Events;

use App\Models\Budget;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * BudgetThresholdReached — fired when a department budget crosses the 75 % or
 * 90 % consumption threshold.
 *
 * Listeners attached to this event are responsible for dispatching queued
 * notification jobs to Finance_Officer and Tenant_Admin users.
 *
 * Requirements: 13.7
 */
class BudgetThresholdReached
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  Budget  $budget             The budget that crossed the threshold.
     * @param  int     $thresholdPercent   The threshold that was crossed (75 or 90).
     * @param  string  $utilizationPercent Current utilization percentage string (e.g. '76.50').
     * @param  string  $committedAmount    Total committed (encumbered + spent) as BCMath string.
     * @param  string  $tenantId           UUID of the owning tenant.
     */
    public function __construct(
        public readonly Budget $budget,
        public readonly int    $thresholdPercent,
        public readonly string $utilizationPercent,
        public readonly string $committedAmount,
        public readonly string $tenantId,
    ) {}
}
