<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued job that sends a welcome email with a 24-hour password-setup link
 * to a newly created user.
 *
 * Dispatched on the 'notifications' queue (high priority).
 * Requirements: 4.2
 */
class SendWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private readonly string $userEmail,
        private readonly string $userName,
        private readonly string $setupToken,
        private readonly string $setupUrl,
        private readonly string $tenantName,
    ) {
        $this->onQueue(config('app.notification_queue', 'notifications'));
    }

    /**
     * Send the welcome email with the password-setup link.
     */
    public function handle(): void
    {
        Mail::send([], [], function ($message) {
            $message
                ->to($this->userEmail, $this->userName)
                ->subject('Welcome to ' . $this->tenantName . ' — Set Up Your Password')
                ->html($this->buildEmailBody());
        });

        Log::info('Welcome email sent', [
            'email' => $this->userEmail,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to send welcome email', [
            'email' => $this->userEmail,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Build the HTML body for the welcome email.
     */
    private function buildEmailBody(): string
    {
        $appName    = e(config('app.name'));
        $tenantName = e($this->tenantName);
        $name       = e($this->userName);
        $setupUrl   = e($this->setupUrl);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Welcome — Set Up Your Password</title>
        </head>
        <body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="color: #1a1a2e; margin-bottom: 24px;">Welcome to {$tenantName}!</h2>
                <p style="color: #333333; line-height: 1.6;">Hello {$name},</p>
                <p style="color: #333333; line-height: 1.6;">
                    Your account has been created on <strong>{$appName}</strong> for <strong>{$tenantName}</strong>.
                    Click the button below to set up your password and activate your account.
                    This link will expire in <strong>24 hours</strong>.
                </p>
                <div style="text-align: center; margin: 32px 0;">
                    <a href="{$setupUrl}"
                       style="background-color: #4f46e5; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">
                        Set Up Password
                    </a>
                </div>
                <p style="color: #666666; font-size: 14px; line-height: 1.6;">
                    If you did not expect this invitation, please ignore this email or contact your administrator.
                </p>
                <p style="color: #666666; font-size: 14px; line-height: 1.6;">
                    If the button above does not work, copy and paste the following URL into your browser:
                </p>
                <p style="color: #4f46e5; font-size: 13px; word-break: break-all;">{$setupUrl}</p>
                <hr style="border: none; border-top: 1px solid #eeeeee; margin: 32px 0;">
                <p style="color: #999999; font-size: 12px; text-align: center;">
                    &copy; {$appName}. All rights reserved.
                </p>
            </div>
        </body>
        </html>
        HTML;
    }
}
