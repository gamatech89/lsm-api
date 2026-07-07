<?php

use App\Models\Project;
use App\Models\User;

function makeCompletedBackup(Project $project, User $creator): \App\Models\Backup
{
    return $project->backups()->create([
        'created_by' => $creator->id,
        'type' => 'manual',
        'status' => 'completed',
        'file_path' => 'backups/does-not-exist.zip',
        'started_at' => now(),
        'completed_at' => now(),
    ]);
}

test('admin can view a single backup (shallow route resolves project)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();
    $backup = makeCompletedBackup($project, $admin);

    $response = $this->actingAs($admin)->getJson("/api/v1/backups/{$backup->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $backup->id);
});

test('admin download reaches the file stage (past ownership check)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();
    $backup = makeCompletedBackup($project, $admin);

    $response = $this->actingAs($admin)->getJson("/api/v1/backups/{$backup->id}/download");

    // File is intentionally missing, so we expect 'Backup file not found',
    // NOT 'Backup not found' (which would mean project resolution failed).
    $response->assertStatus(404);
    $response->assertJsonPath('message', 'Backup file not found');
});

test('admin can trigger a restore of a completed backup', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();
    $backup = makeCompletedBackup($project, $admin);

    $response = $this->actingAs($admin)->postJson("/api/v1/backups/{$backup->id}/restore");

    $response->assertOk();
    $response->assertJsonPath('message', 'Backup restore started');
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RestoreBackupJob::class);
});

test('admin can delete a single backup', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();
    $backup = makeCompletedBackup($project, $admin);

    $response = $this->actingAs($admin)->deleteJson("/api/v1/backups/{$backup->id}");

    $response->assertOk();
    $response->assertJsonPath('message', 'Backup deleted');
});

test('a developer cannot download a backup from a project they are not on', function () {
    $developer = User::factory()->create(['role' => 'developer']);
    $project = Project::factory()->create(); // developer not assigned
    $owner = User::factory()->create(['role' => 'admin']);
    $backup = makeCompletedBackup($project, $owner);

    $response = $this->actingAs($developer)->getJson("/api/v1/backups/{$backup->id}/download");

    $response->assertStatus(403);
});
