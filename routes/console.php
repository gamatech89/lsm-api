<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Health checks run 3 times per day: 8:00, 14:00, and 20:00
| Uses --deep flag to check WordPress plugin health data where available
|
*/

Schedule::command('projects:health-check --deep --notify')
    ->dailyAt('08:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('projects:health-check --deep --notify')
    ->dailyAt('14:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('projects:health-check --deep --notify')
    ->dailyAt('20:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| SSL Expiry Alerts
|--------------------------------------------------------------------------
|
| Checks all projects for SSL certificates expiring within 30 days and
| sends alerts. Runs daily at 09:00, after the morning health check has
| refreshed ssl_expires_at values.
|
*/

Schedule::command('sites:check-ssl-expiry')
    ->dailyAt('09:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('ssl-expiry-check')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Live Uptime Monitoring
|--------------------------------------------------------------------------
|
| Checks all monitored sites at configurable intervals.
| Settings can be changed via Admin Settings panel or .env file.
|
| Detects: HTTP errors (4xx, 5xx), Redirects (3xx), Timeouts, SSL issues
|
*/

// Dynamic scheduling - checks database settings first, then falls back to config
$uptimeInterval = \App\Models\Setting::getOrConfig('uptime.interval', 'uptime.check_interval') ?? 5;
$uptimeEnabled = \App\Models\Setting::getOrConfig('uptime.enabled', 'uptime.enabled') ?? true;

if ($uptimeEnabled) {
    Schedule::command('sites:check-uptime')
        ->cron("*/{$uptimeInterval} * * * *") // Every X minutes
        ->withoutOverlapping()
        ->runInBackground()
        ->name('live-uptime-monitoring')
        ->onOneServer();
}

/*
|--------------------------------------------------------------------------
| Backup Tasks
|--------------------------------------------------------------------------
|
| Scheduled backups run based on the configured frequency (daily, weekly, monthly)
| Cleanup job runs daily to remove old backups based on retention policy
|
*/

// Run scheduled backups at 3:00 AM (configurable time in config/backup.php)
Schedule::job(new \App\Jobs\ScheduledBackupJob())
    ->daily()
    ->at(config('backup.schedule.time', '03:00'))
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->name('scheduled-backups')
    ->onOneServer();

// Cleanup old backups daily at 4:00 AM
Schedule::job(new \App\Jobs\CleanupOldBackupsJob())
    ->dailyAt('04:00')
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->name('cleanup-old-backups')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Error Sync Tasks
|--------------------------------------------------------------------------
|
| Sync PHP errors from WordPress sites to the local database
|
*/

// Sync PHP errors every hour
Schedule::job(new \App\Jobs\SyncPhpErrorsJob())
    ->hourly()
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->name('sync-php-errors')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Queue Worker
|--------------------------------------------------------------------------
|
| Queue is set to 'sync' driver on Hostinger shared hosting because
| pcntl_signal() is not available. Jobs execute immediately inline.
| No separate queue worker is needed.
|
*/

/*
|--------------------------------------------------------------------------
| Domain Expiry Checks (WHOIS)
|--------------------------------------------------------------------------
|
| Checks domain registration expiry dates via WHOIS lookup.
| Runs weekly on Mondays at 5:00 AM.
|
*/

Schedule::command('projects:check-domains')
    ->weeklyOn(1, '05:00') // Monday at 5:00 AM
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('domain-expiry-check')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Security Scanning
|--------------------------------------------------------------------------
|
| Automated malware and security scanning of WordPress sites.
| Full scan runs weekly on Sundays at 3:00 AM.
|
*/

Schedule::command('security:scan --notify')
    ->weeklyOn(0, '03:00') // Sunday at 3:00 AM
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('security-scan-weekly')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Todo Due Date Reminders
|--------------------------------------------------------------------------
|
| Sends reminder notifications to assigned users when their todos are
| due within the next 24 hours. Runs hourly with dedup logic.
|
*/

Schedule::command('todos:send-due-reminders')
    ->hourly()
    ->timezone('Europe/Berlin')
    ->withoutOverlapping()
    ->name('todo-due-reminders')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Ephemeral Secret Purge
|--------------------------------------------------------------------------
|
| Deletes expired and already-viewed ephemeral-secret tombstones once they
| are past the 7-day retention window.
|
*/

Schedule::command('ephemeral-secrets:purge')
    ->daily()
    ->onOneServer()
    ->name('ephemeral-secrets-purge');
