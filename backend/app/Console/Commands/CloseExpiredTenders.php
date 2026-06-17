<?php

namespace App\Console\Commands;

use App\Services\TenderService;
use Illuminate\Console\Command;

/**
 * CloseExpiredTenders — Artisan command that automatically closes tenders whose
 * submission_deadline has passed.
 *
 * Registered in routes/console.php as 'tenders:close-expired'
 * and runs every 15 minutes.
 *
 * For each published tender past its deadline this command:
 *  1. Transitions tender status from `published` to `closed`
 *  2. Disqualifies any bids still in `draft` status (supplier never submitted)
 *  3. Dispatches a WriteAuditLogJob recording the system-initiated closure
 *
 * Requirements: 8.6
 */
class CloseExpiredTenders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenders:close-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close all published tenders whose submission deadline has passed';

    /**
     * Execute the console command.
     */
    public function handle(TenderService $service): int
    {
        $this->info('Scanning for expired tenders...');

        $closed = $service->closeExpired();

        if ($closed === 0) {
            $this->info('No expired tenders found. Nothing to do.');
        } else {
            $this->info("Closed {$closed} expired tender(s).");
        }

        return Command::SUCCESS;
    }
}
