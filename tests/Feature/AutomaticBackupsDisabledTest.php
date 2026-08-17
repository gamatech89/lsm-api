<?php

use App\Jobs\CreateBackupJob;
use App\Jobs\ScheduledBackupJob;
use App\Models\Project;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * Backups must only ever be created on explicit human request (SPA button,
 * API POST, MCP tool). Nothing may back up a site just because the plugin
 * got connected (health_check_secret set) — that is what the nightly
 * ScheduledBackupJob used to do for every connected project, forever, as
 * long as the backup kept failing.
 *
 * Automatic scheduling stays available as an explicit opt-in via
 * BACKUP_SCHEDULE_ENABLED=true, but is OFF by default and, when off, is not
 * even registered with the scheduler.
 */

test('automatic backup scheduling is disabled by default', function () {
    // Assert the SHIPPED default in config/backup.php, independent of whatever
    // BACKUP_SCHEDULE_ENABLED happens to be in the ambient environment
    // (phpunit.xml pins it to false, a developer's .env may opt in): evaluate
    // the config file with the variable removed from every source env() reads.
    $key = 'BACKUP_SCHEDULE_ENABLED';
    $saved = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);

    try {
        $shipped = require config_path('backup.php');
    } finally {
        if ($saved[0] !== false) {
            putenv("{$key}={$saved[0]}");
        }
        if ($saved[1] !== null) {
            $_ENV[$key] = $saved[1];
        }
        if ($saved[2] !== null) {
            $_SERVER[$key] = $saved[2];
        }
    }

    expect($shipped['schedule']['enabled'])->toBeFalse();
});

test('scheduler does not register the scheduled-backups task when disabled', function () {
    $names = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($names)->not->toContain('scheduled-backups');
});

test('scheduled backup job creates nothing for freshly connected projects when disabled', function () {
    Queue::fake();
    config(['backup.schedule.enabled' => false]);

    // A project that has just been connected to the plugin and has no backups.
    Project::factory()->create([
        'status' => 'active',
        'url' => 'https://fresh-site.example',
        'health_check_secret' => 'secret-for-fresh-project',
    ]);

    (new ScheduledBackupJob())->handle();

    $this->assertDatabaseCount('backups', 0);
    Queue::assertNothingPushed();
});

test('scheduled backup job still works when explicitly opted in', function () {
    Queue::fake();
    config(['backup.schedule.enabled' => true]);

    $project = Project::factory()->create([
        'status' => 'active',
        'url' => 'https://opted-in-site.example',
        'health_check_secret' => 'secret-for-opted-in-project',
    ]);

    (new ScheduledBackupJob())->handle();

    $this->assertDatabaseHas('backups', [
        'project_id' => $project->id,
        'type' => 'scheduled',
    ]);
    Queue::assertPushed(CreateBackupJob::class, 1);
});
