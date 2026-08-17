<?php

use App\Jobs\CreateBackupJob;
use App\Jobs\ScheduledBackupJob;
use App\Jobs\SyncPhpErrorsJob;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The projects table has no 'archived' boolean — archival is modelled as
 * status = 'archived' (string, default 'active'). The scheduled jobs must
 * filter with ->where('status', '!=', 'archived') like the sibling console
 * commands (CheckSslExpiry, CheckDomainExpiry, CheckSiteUptime,
 * CheckProjectsHealth) — not ->where('archived', false), which throws
 * "Unknown column 'archived'" and kills the job.
 */
function seedActiveAndArchivedProjects(): array
{
    $active = Project::factory()->create([
        'status' => 'active',
        'url' => 'https://active-site.example',
        'health_check_secret' => 'secret-for-active-project',
    ]);

    $archived = Project::factory()->create([
        'status' => 'archived',
        'url' => 'https://archived-site.example',
        'health_check_secret' => 'secret-for-archived-project',
    ]);

    return [$active, $archived];
}

test('scheduled backup job backs up active projects and skips archived ones', function () {
    Queue::fake();
    // Automatic backups are opt-in (off by default); enable them here because
    // this test is about the archived-status filter, not the opt-in gate.
    config(['backup.schedule.enabled' => true]);

    [$active, $archived] = seedActiveAndArchivedProjects();

    (new ScheduledBackupJob())->handle();

    $this->assertDatabaseHas('backups', [
        'project_id' => $active->id,
        'type' => 'scheduled',
    ]);
    $this->assertDatabaseMissing('backups', [
        'project_id' => $archived->id,
    ]);

    Queue::assertPushed(CreateBackupJob::class, 1);
});

test('php error sync job contacts active projects and skips archived ones', function () {
    Http::fake([
        '*' => Http::response(['success' => true, 'data' => []], 200),
    ]);

    seedActiveAndArchivedProjects();

    (new SyncPhpErrorsJob())->handle();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'active-site.example'));
});
