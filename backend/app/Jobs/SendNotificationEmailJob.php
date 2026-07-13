<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SendNotificationEmailJob — delivers an email copy of an in-app notification.
 *
 * Queue : notifications
 * Tries : 3
 * Backoff: 10 s → 30 s → 90 s  (exponential, matches other notification jobs)
 *
 * On final failure (after all retries are exhausted) the job notifies all
 * System_Admin users by persisting a system-level in-app notification record
 * so they can investigate the email delivery failure.
 *
 * Requirements: 15.2, 15.8, 15.9
 */
class SendNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Maximum number of attempts before the job is failed. */
    public int $tries = 3;

    /**
     * Retry delays in seconds between attempts.
     * attempt 1 → wait 10 s → attempt 2 → wait 30 s → attempt 3 → wait 90 s → fail
     *
     * @var array<int>
     */
    public array $backoff = [10, 30, 90];

    /**
     * @param  string       $notificationId  UUID of the persisted Notification record.
     * @param  string       $tenantId        Tenant UUID — stored for use in failed().
     * @param  string       $recipientEmail  Destination email address.
     * @param  string       $recipientName   Recipient display name.
     * @param  string       $subject         Email subject line.
     * @param  string       $bodyHtml        Pre-rendered HTML body.
     * @param  string       $bodyText        Plain-text fallback body.
     */
    public function __construct(
        private readonly string $notificationId,
        private readonly string $tenantId,
        private readonly string $recipientEmail,
        private readonly string $recipientName,
        private readonly string $subject,
        private readonly string $bodyHtml,
        private readonly string $bodyText,
    ) {
        $this->onQueue('notifications');
    }

    // -------------------------------------------------------------------------
    // Execute
    // -------------------------------------------------------------------------

    /**
     * Send the notification email to the recipient.
     *
     * Uses Laravel's raw Mail::send() so the job remains self-contained and
     * does not require a Mailable class for this low-level notification path.
     *
     * Requirements: 15.2
     */
    public function handle(): void
    {
        Mail::send(
            [],
            [],
            function (Message $mail) {
                $mail->to($this->recipientEmail, $this->recipientName)
                     ->subject($this->subject)
                     ->setBody($this->bodyHtml, 'text/html')
                     ->text($this->bodyText);
            }
        );

        Log::info('SendNotificationEmailJob: email delivered', [
            'notification_id' => $this->notificationId,
            'recipient'       => $this->recipientEmail,
        ]);
    }

    // -------------------------------------------------------------------------
    // Final failure handler
    // -------------------------------------------------------------------------

    /**
     * Called by Laravel after all retry attempts have been exhausted.
     *
     * Persists an in-app Notification for every active System_Admin user so
     * the team is aware of the email delivery failure without relying on email
     * itself (which is the broken channel).
     *
     * Requirements: 15.9
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendNotificationEmailJob: permanently failed after all retries', [
            'notification_id' => $this->notificationId,
            'recipient'       => $this->recipientEmail,
            'error'           => $exception->getMessage(),
        ]);

        // Alert all active System_Admin users across all tenants.
        $admins = User::withoutGlobalScopes()
            ->whereHas('roles', fn ($q) => $q->where('name', 'System_Admin'))
            ->where('status', 'active')
            ->get();

        foreach ($admins as $admin) {
            try {
                \App\Models\Notification::withoutGlobalScopes()->create([
                    'tenant_id'  => $admin->tenant_id ?? $this->tenantId,
                    'user_id'    => $admin->id,
                    'event_type' => 'email_delivery_failed',
                    'title'      => 'Email delivery failure',
                    'message'    => "Failed to deliver email notification (ID: {$this->notificationId}) "
                                  . "to {$this->recipientEmail} after 3 attempts. Error: {$exception->getMessage()}",
                    'data'       => [
                        'notification_id' => $this->notificationId,
                        'tenant_id'       => $this->tenantId,
                        'recipient_email' => $this->recipientEmail,
                        'subject'         => $this->subject,
                        'error'           => $exception->getMessage(),
                    ],
                    'is_read'    => false,
                    'read_at'    => null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $inner) {
                Log::critical('SendNotificationEmailJob: could not alert System_Admin', [
                    'admin_id' => $admin->id,
                    'error'    => $inner->getMessage(),
                ]);
            }
        }
    }
}
