<?php

namespace App\Console\Commands;

use App\Services\ApprovalWorkflowService;
use Illuminate\Console\Command;

/**
 * ProcessEscalations — Artisan command that dispatches escalation notifications
 * for overdue pending approvals.
 *
 * Registered in routes/console.php to run hourly.
 *
 * Calls ApprovalWorkflowService::escalatePendingApprovals() which scans all
 * pending Approval records across all tenants and fires SendEscalationNotificationJob
 * for any that have exceeded their configured escalation_hours (default 48h).
 *
 * Escalation target priority:
 *  1. Approver's supervisor (User.supervisor_id)
 *  2. Tenant_Admin of the approver's tenant (fallback)
 *
 * Requirements: 6.8, 6.9
 */
class ProcessEscalations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pmp:process-escalations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch escalation notifications for overdue pending approvals';

    /**
     * Execute the console command.
     */
    public function handle(ApprovalWorkflowService $service): int
    {
        $dispatched = $service->escalatePendingApprovals();

        $this->info("Dispatched {$dispatched} escalation notifications.");

        return Command::SUCCESS;
    }
}
