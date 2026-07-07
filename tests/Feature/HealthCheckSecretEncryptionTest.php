<?php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('health_check_secret is encrypted at rest but reads back as plaintext', function () {
    $project = Project::factory()->create([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'PLAINKEY123',
    ]);

    $raw = DB::table('projects')->where('id', $project->id)->value('health_check_secret');
    expect($raw)->not->toBe('PLAINKEY123');        // stored encrypted
    expect($raw)->not->toBeNull();

    expect($project->fresh()->health_check_secret)->toBe('PLAINKEY123'); // transparent on read
});

test('saving a secret populates the deterministic lookup hash', function () {
    $project = Project::factory()->create([
        'url' => 'https://client.example.com',
        'health_check_secret' => 'PLAINKEY123',
    ]);

    $hash = DB::table('projects')->where('id', $project->id)->value('health_check_secret_hash');
    expect($hash)->toBe(hash('sha256', 'PLAINKEY123'));
});

test('a legacy plaintext secret is still readable (graceful cast fallback)', function () {
    $project = Project::factory()->create(['url' => 'https://legacy.example.com']);
    // Simulate a pre-migration row: write plaintext directly, bypassing the cast.
    DB::table('projects')->where('id', $project->id)->update(['health_check_secret' => 'LEGACYPLAIN']);

    expect($project->fresh()->health_check_secret)->toBe('LEGACYPLAIN');
});

test('the backfill migration encrypts legacy plaintext rows and is idempotent', function () {
    $project = Project::factory()->create(['url' => 'https://legacy2.example.com']);
    // Simulate a pre-migration row: plaintext secret, no hash.
    DB::table('projects')->where('id', $project->id)->update([
        'health_check_secret' => 'LEGACYSECRET',
        'health_check_secret_hash' => null,
    ]);

    $migration = require database_path('migrations/2026_07_07_120100_encrypt_existing_health_check_secrets.php');
    $migration->up();

    $raw = DB::table('projects')->where('id', $project->id)->value('health_check_secret');
    expect($raw)->not->toBe('LEGACYSECRET');                              // now encrypted at rest
    expect($project->fresh()->health_check_secret)->toBe('LEGACYSECRET'); // still readable
    expect(DB::table('projects')->where('id', $project->id)->value('health_check_secret_hash'))
        ->toBe(hash('sha256', 'LEGACYSECRET'));

    // Running it again must not double-encrypt or corrupt the value.
    $migration->up();
    expect($project->fresh()->health_check_secret)->toBe('LEGACYSECRET');
});

test('the support webhook matches a project whose secret is encrypted', function () {
    $project = Project::factory()->create([
        'url' => 'https://site.example.com',
        'health_check_secret' => 'WEBHOOKKEY',
    ]);

    $response = $this->postJson('/api/v1/webhooks/support-ticket', [
        'api_key' => 'WEBHOOKKEY',
        'site_url' => 'https://site.example.com',
        'type' => 'bug',
        'subject' => 'Broken thing',
        'message' => 'It broke',
        'client_email' => 'client@example.com',
    ]);

    $response->assertStatus(201);
    expect(SupportTicket::where('project_id', $project->id)->count())->toBe(1);
});
