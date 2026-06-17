<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued job that sends a time-limited (60-minute) password reset email.
 *
 * Dispatched on the 'notifications' queue (high priority).
 * Requirements: 2.5
 */
class SendPasswordResetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private readonly string $userEmail,
        private readonly string $userName,
        private readonly string $resetToken,
        private readonly string $resetUrl,
    ) {
        $this->onQueue(config('app.notification_queue', 'notifications'));
    }

    /**
     * Send the password reset email.
     */
    public function handle(): void
    {
        Mail::send([], [], function ($message) {
            $message
                ->to($this->userEmail, $this->userName)
                ->subject('Password Reset Request — ' . config('app.name'))
                ->html($this->buildEmailBody());
        });

        Log::info('Password reset email sent', [
            'email' => $this->userEmail,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to send password reset email', [
            'email' => $this->userEmail,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Build the HTML body for the password reset email.
     */
    private function buildEmailBody(): string
    {
        $appName  = e(config('app.name'));
        $name     = e($this->userName);
        $resetUrl = e($this->resetUrl);
        $expiry   = (int) config('app.password_reset_expiry', 60);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Password Reset</title>
        </head>
        <body style="font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="color: #1a1a2e; margin-bottom: 24px;">Password Reset Request</h2>
                <p style="color: #333333; line-height: 1.6;">Hello {$name},</p>
                <p style="color: #333333; line-height: 1.6;">
                    We received a request to reset the password for your <strong>{$appName}</strong> account.
                    Click the button below to set a new password. This link will expire in <strong>{$expiry} minutes</strong>.
                </p>
                <div style="text-align: center; margin: 32px 0;">
                    <a href="{$resetUrl}"
                       style="background-color: #4f46e5; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">
                        Reset Password
                    </a>
                </div>
                <p style="color: #666666; font-size: 14px; line-height: 1.6;">
                    If you did not request a password reset, please ignore this email. Your password will remain unchanged.
                </p>
                <p style="color: #666666; font-size: 14px; line-height: 1.6;">
                    If the button above does not work, copy and paste the following URL into your browser:
                </p>
                <p style="color: #4f46e5; font-size: 13px; word-break: break-all;">{$resetUrl}</p>
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
