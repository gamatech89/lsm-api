<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Assert exactly one Log::info('hardening', $context) line with this context.
 */
function assertHardeningLogged(array $expected): void
{
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'hardening' && $context == $expected)
        ->once();
}

test('turning a rule on writes one hardening log line', function () {
    Log::spy();
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_uploads_php', 'enabled' => true])
        ->assertOk();

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'enable',
        'rule' => 'block_uploads_php',
        'outcome' => 'ok',
    ]);
});

test('a rolled-back disable is logged with the plugin reason as outcome', function () {
    Log::spy();
    Http::fake(['*' => Http::response(hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertStatus(422);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'disable',
        'rule' => 'block_archives',
        'outcome' => 'asset_broken',
    ]);
});

test('a pause is logged, also when the outcome is unknown', function () {
    Log::spy();
    Http::fake(['*' => Http::failedConnection()]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 30])
        ->assertStatus(502);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'pause',
        'rule' => 'block_archives',
        'outcome' => 'unreachable',
    ]);
});

test('a resume is logged, also against an outdated plugin', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['code' => 'rest_no_route'], 404)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertStatus(409);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'resume',
        'rule' => 'block_archives',
        'outcome' => 'plugin_outdated',
    ]);
});

test('a busy pause is logged with the plugin reason (busy) as outcome', function () {
    Log::spy();
    Http::fake(['*' => Http::response(hardeningPluginBody(false, 'busy', hardeningPluginStatus('paused', 1790000000, true)), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 15])
        ->assertStatus(409);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'pause',
        'rule' => 'block_archives',
        'outcome' => 'busy',
    ]);
});

test('a resume rejected by the site is logged with outcome unauthorized', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['message' => 'Forbidden'], 403)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertStatus(502);

    assertHardeningLogged([
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'action' => 'resume',
        'rule' => 'block_archives',
        'outcome' => 'unauthorized',
    ]);
});

test('refused and invalid calls never reach the plugin and write no hardening line', function () {
    Log::spy();
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertForbidden();
    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 45])
        ->assertStatus(422);

    Log::shouldNotHaveReceived('info', ['hardening', \Mockery::any()]);
});

test('reading the status writes no hardening line', function () {
    Log::spy();
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk();

    Log::shouldNotHaveReceived('info', ['hardening', \Mockery::any()]);
});
