<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes / Scheduled Commands
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// Process approval escalations — runs every hour
Schedule::command('pmp:process-escalations')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Send contract renewal alerts — runs daily at 08:00 UTC
Schedule::command('contracts:send-renewal-alerts')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Mark overdue purchase orders — runs every 30 minutes
Schedule::command('purchase-orders:mark-overdue')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Send delivery reminders (7-day and 1-day) — runs daily at 07:00 UTC
Schedule::command('purchase-orders:send-delivery-reminders')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();

// Send payment due reminders (5 days before due) — runs daily at 09:00 UTC
Schedule::command('payments:send-due-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Close tenders past their deadline — runs every 15 minutes
Schedule::command('tenders:close-expired')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Send supplier document expiry reminders (30 days prior) — runs daily at 10:00 UTC
Schedule::command('suppliers:send-document-expiry-reminders')
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->runInBackground();

// Send bid deadline approaching notifications (24h before) — runs every hour
Schedule::command('tenders:send-bid-deadline-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
