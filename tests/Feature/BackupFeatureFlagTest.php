<?php

use App\Mcp\Tools\WpCreateBackupTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * The whole backup feature sits behind BACKUP_ENABLED (config('backup.enabled')).
 * It is OFF by default: the feature is not used yet and its only known effect
 * on a client site is an orphaned multi-GB zip when the 30 s create timeout
 * fires. When off, every backup route except GET /backups/settings answers 403,
 * the MCP backup tools are not registered, and no backup scheduler task exists.
 * GET /backups/settings stays reachable so the SPA can learn the flag and hide
 * the UI. Re-enable with BACKUP_ENABLED=true (+ config:cache).
 */
$backupToolNames = ['wp-create-backup', 'wp-list-backups', 'wp-restore-backup', 'wp-download-backup'];

test('the backup feature is disabled by default', function () {
    // Assert the shipped default independently of any ambient env override.
    $key = 'BACKUP_ENABLED';
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

    expect($shipped['enabled'])->toBeFalse();
});

test('backup routes answer 403 when the feature is disabled', function () {
    config(['backup.enabled' => false]);
    Queue::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();
    // A real row so the shallow {backup} binding resolves and the gate (not a 404) is what answers.
    $backup = $project->backups()->create([
        'created_by' => $admin->id, 'type' => 'manual', 'status' => 'completed',
        'file_path' => 'backups/does-not-exist.zip', 'started_at' => now(), 'completed_at' => now(),
    ]);

    $this->actingAs($admin)->postJson("/api/v1/projects/{$project->id}/backups", [])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Backups are currently disabled.');
    $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}/backups")->assertStatus(403);
    $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}/backups-stats")->assertStatus(403);
    $this->actingAs($admin)->getJson("/api/v1/backups/{$backup->id}")->assertStatus(403);
    $this->actingAs($admin)->getJson("/api/v1/backups/{$backup->id}/download")->assertStatus(403);
    $this->actingAs($admin)->postJson("/api/v1/backups/{$backup->id}/restore")->assertStatus(403);
    $this->actingAs($admin)->deleteJson("/api/v1/backups/{$backup->id}")->assertStatus(403);

    // Nothing was created, deleted or dispatched.
    $this->assertDatabaseCount('backups', 1);
    Queue::assertNothingPushed();
});

test('backup settings stay readable and expose the flag when disabled', function () {
    config(['backup.enabled' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/backups/settings')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);
});

test('backup routes work and settings report enabled when the feature is on', function () {
    config(['backup.enabled' => true]);
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();

    $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}/backups")->assertOk();
    $this->actingAs($admin)->getJson('/api/v1/backups/settings')
        ->assertOk()
        ->assertJsonPath('data.enabled', true);
});

test('mcp tools/list hides the backup tools when the feature is disabled', function () use ($backupToolNames) {
    config(['backup.enabled' => false]);
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('wildcard', ['*'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 50],
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($names->intersect($backupToolNames))->toBeEmpty();
    // Control: the listing itself still works — unrelated tools remain visible.
    expect($names)->toContain('get-dashboard');
});

test('mcp tools/list shows the backup tools when the feature is on', function () use ($backupToolNames) {
    config(['backup.enabled' => true]);
    $user = User::factory()->create(['role' => 'admin']);
    $token = $user->createToken('wildcard', ['*'], now()->addYear())->plainTextToken;

    $names = collect($this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 50],
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($names->intersect($backupToolNames)->count())->toBe(count($backupToolNames));
});

test('a directly invoked mcp backup tool refuses when the feature is disabled', function () {
    // shouldRegister() already keeps the tool out of tools/call routing; this
    // covers a caller that resolves the class and invokes handle() itself.
    config(['backup.enabled' => false]);
    Queue::fake();
    $user = actingWithScopes(User::factory()->create(['role' => 'admin']), ['*']);
    app('auth')->guard()->setUser($user);
    $project = Project::factory()->create();

    $response = app()->call([new WpCreateBackupTool, 'handle'], [
        'request' => new \Laravel\Mcp\Request(['project_id' => $project->id]),
    ]);

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('disabled');
    $this->assertDatabaseCount('backups', 0);
    Queue::assertNothingPushed();
});

test('no backup scheduler task is registered when the feature is disabled', function () {
    // routes/console.php registers tasks at boot; re-evaluate it against a
    // fresh Schedule with the feature off (and nightly scheduling explicitly
    // opted in, to prove the feature flag alone is sufficient to suppress it).
    config(['backup.enabled' => false, 'backup.schedule.enabled' => true]);
    $schedule = new Schedule;
    \Illuminate\Support\Facades\Schedule::swap($schedule);
    require base_path('routes/console.php');

    $names = collect($schedule->events())->map(fn ($e) => $e->description);

    expect($names)->not->toContain('scheduled-backups');
    expect($names)->not->toContain('cleanup-old-backups');
});

test('the cleanup scheduler task is registered when the feature is on', function () {
    config(['backup.enabled' => true, 'backup.schedule.enabled' => false]);
    $schedule = new Schedule;
    \Illuminate\Support\Facades\Schedule::swap($schedule);
    require base_path('routes/console.php');

    $names = collect($schedule->events())->map(fn ($e) => $e->description);

    expect($names)->toContain('cleanup-old-backups');
    expect($names)->not->toContain('scheduled-backups');
});
