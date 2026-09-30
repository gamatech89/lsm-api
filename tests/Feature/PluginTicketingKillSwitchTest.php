<?php

use App\Models\Project;
use App\Models\SupportTicket;

/**
 * Ticket intake from the WordPress plugin sits behind PLUGIN_TICKETING_ENABLED
 * (config('ticketing.plugin_intake_enabled')). ON by default; production
 * switches it off with PLUGIN_TICKETING_ENABLED=false (+ config:cache).
 * When off, the legacy webhook and the two plugin write routes answer 503 and
 * store nothing, while reading tickets from the plugin keeps working.
 */
function killSwitchProject(string $key): Project
{
    return Project::factory()->create(['health_check_secret' => $key]);
}

test('plugin ticket intake is enabled by default', function () {
    $key = 'PLUGIN_TICKETING_ENABLED';
    $saved = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);

    try {
        $shipped = require config_path('ticketing.php');
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

    expect($shipped['plugin_intake_enabled'])->toBeTrue();
});

test('the legacy webhook answers 503 and stores nothing when intake is disabled', function () {
    config(['ticketing.plugin_intake_enabled' => false]);
    killSwitchProject('KS_HOOK');

    $this->postJson('/api/v1/webhooks/support-ticket', [
        'api_key' => 'KS_HOOK',
        'subject' => 'Blocked',
        'message' => 'Should not be stored',
        'issue_type' => 'question',
    ])->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Support ticket submission is temporarily disabled.');

    expect(SupportTicket::count())->toBe(0);
});

test('plugin ticket creation and replies answer 503 when intake is disabled, reads still work', function () {
    config(['ticketing.plugin_intake_enabled' => false]);
    $project = killSwitchProject('KS_PLUGIN');
    $ticket = SupportTicket::create([
        'project_id' => $project->id, 'type' => 'bug', 'subject' => 'Existing', 'message' => 'desc',
        'client_email' => 'client@example.com', 'client_name' => 'Client', 'status' => 'open', 'priority' => 'high',
    ]);
    $headers = ['X-LSM-Key' => 'KS_PLUGIN', 'Accept' => 'application/json'];

    $this->postJson('/api/v1/plugin/support-tickets', [
        'subject' => 'Blocked', 'message' => 'no', 'type' => 'question',
    ], $headers)->assertStatus(503);

    $this->postJson("/api/v1/plugin/support-tickets/{$ticket->id}/messages", [
        'message' => 'no',
    ], $headers)->assertStatus(503);

    expect(SupportTicket::count())->toBe(1);
    expect($ticket->fresh()->messages()->count())->toBe($ticket->messages()->count());

    $this->getJson('/api/v1/plugin/support-tickets', $headers)->assertOk();
    $this->getJson("/api/v1/plugin/support-tickets/{$ticket->id}", $headers)->assertOk();
});

test('the kill switch never touches the platform ticket routes', function () {
    config(['ticketing.plugin_intake_enabled' => false]);
    $admin = \App\Models\User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);

    $this->actingAs($admin)->getJson('/api/v1/support-tickets')->assertOk();
});
