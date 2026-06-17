<?php

namespace App\Jobs;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that notifies a supplier of the bid evaluation outcome
 * (won or lost) after a winner is selected.
 *
 * Dispatched on the 'notifications' queue with a 3-attempt, exponential
 * back-off retry policy.
 *
 * Requirements: 9.6
 */
class SendBidEvaluationOutcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries  = 3;
    public array $backoff = [10, 30, 90];

    /**
     * @param  string  $outcome  'won' | 'lost'
     */
    public function __construct(
        private readonly string  $tenantId,
        private readonly string  $userId,
        private readonly string  $tenderId,
        private readonly string  $tenderTitle,
        private readonly string  $tenderReference,
        private readonly string  $bidId,
        private readonly string  $outcome,
        private readonly ?string $justification = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        [$title, $message] = $this->buildMessage();

        try {
            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $this->tenantId,
                'user_id'    => $this->userId,
                'event_type' => 'bid_evaluation_completed',
                'title'      => $title,
                'message'    => $message,
                'data'       => [
                    'tender_id'        => $this->tenderId,
                    'tender_reference' => $this->tenderReference,
                    'bid_id'           => $this->bidId,
                    'outcome'          => $this->outcome,
                    'justification'    => $this->justification,
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendBidEvaluationOutcomeJob: failed to create notification', [
                'tender_id' => $this->tenderId,
                'user_id'   => $this->userId,
                'error'     => $e->getMessage(),
            ]);

            throw $e; // Allow retry
        }
    }

    /** @return array{0: string, 1: string} */
    private function buildMessage(): array
    {
        if ($this->outcome === 'won') {
            return [
                "Congratulations! You have won the tender: {$this->tenderTitle}",
                "Your bid (ID: {$this->bidId}) has been selected as the winning bid for tender "
                . "(Ref: {$this->tenderReference}). "
                . ($this->justification ? "Justification: {$this->justification}" : ''),
            ];
        }

        return [
            "Bid outcome notification: {$this->tenderTitle}",
            "We regret to inform you that your bid (ID: {$this->bidId}) was not selected "
            . "for tender (Ref: {$this->tenderReference}). "
            . "Thank you for participating.",
        ];
    }
}
