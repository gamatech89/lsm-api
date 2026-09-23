<?php

use App\Models\ProjectHardeningPause;
use Illuminate\Support\Facades\Http;

function fakeHardeningPause(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/pause' => Http::response($body, $http)]);
}

// ---------------------------------------------------------------------------
// Authorization and validation
// ---------------------------------------------------------------------------

test('an assigned developer can pause', function () {
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addMinutes(15)->timestamp)));
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 15])
        ->assertOk()
        ->assertJsonPath('can', ['pause' => true, 'enable' => false, 'disable' => false]);
});

test('an unassigned manager cannot pause: 403, no row, nothing sent', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertForbidden();

    expect(ProjectHardeningPause::count())->toBe(0);
    Http::assertNothingSent();
});

test('authorization comes before validation on pause', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('viewer'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 45])
        ->assertForbidden();
});

test('minutes must be 15, 30 or 60: no row, nothing sent', function (array $payload) {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['minutes']);

    expect(ProjectHardeningPause::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    '45' => [['minutes' => 45]],
    '0' => [['minutes' => 0]],
    'text' => [['minutes' => 'an hour']],
    'float' => [['minutes' => 15.5]],
]);

// ---------------------------------------------------------------------------
// Write-ahead row lifecycle
// ---------------------------------------------------------------------------

test('the pause row is written before the plugin is called', function () {
    $openRowsDuringCall = null;
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$openRowsDuringCall, &$seenTimeout) {
        $openRowsDuringCall = ProjectHardeningPause::open()->count();
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addHour()->timestamp)), 200);
    });
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertOk();

    expect($openRowsDuringCall)->toBe(1);
    expect($seenTimeout)->toBe(120);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/pause'
        && $request['minutes'] === 60);
});

test('on success the row takes paused_until from the plugin pause_until and is reported as open_pause', function () {
    $pluginUntil = now()->addMinutes(30)->addSeconds(7)->timestamp;
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', $pluginUntil)));
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 30])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('pause_overdue', false);

    $pause = ProjectHardeningPause::sole();
    expect($pause->project_id)->toBe($project->id);
    expect($pause->user_id)->toBe($admin->id);
    expect($pause->paused_until->timestamp)->toBe($pluginUntil);
    expect($pause->resumed_at)->toBeNull();
    $response->assertJsonPath('open_pause.id', $pause->id);
});

test('on success without a usable pause_until the write-ahead value stays', function () {
    $this->freezeTime();
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', null)));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 15])
        ->assertOk();

    expect(ProjectHardeningPause::sole()->paused_until->timestamp)->toBe(now()->addMinutes(15)->timestamp);
});

test('an explicit success:false deletes the row', function (string $reason, int $expectedHttp) {
    fakeHardeningPause(hardeningPluginBody(false, $reason, hardeningPluginStatus('on'), [], 'Pause failed'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus($expectedHttp)
        ->assertJsonPath('reason', $reason)
        ->assertJsonPath('open_pause', null);

    expect(ProjectHardeningPause::count())->toBe(0);
})->with([
    'foreign rule still blocks' => ['pause_ineffective_foreign_rule', 422],
    'rule not on' => ['not_enabled', 422],
    'lock busy' => ['busy', 409],
]);

test('no response keeps the row so the backstop can reconcile', function (string $failure) {
    $this->freezeTime();
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
    }]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus(502)
        ->assertJsonPath('reason', 'unreachable');

    $pause = ProjectHardeningPause::sole();
    expect($pause->resumed_at)->toBeNull();
    expect($pause->paused_until->timestamp)->toBe(now()->addMinutes(60)->timestamp);
})->with(['connection failure', 'http 500']);

test('a site that answered without pausing (old plugin, rejected key) leaves no row behind', function (int $http, int $expectedHttp, string $reason) {
    fakeHardeningPause(['code' => 'rest_error'], $http);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus($expectedHttp)
        ->assertJsonPath('reason', $reason);

    expect(ProjectHardeningPause::count())->toBe(0);
})->with([
    'old plugin' => [404, 409, 'plugin_outdated'],
    'key rejected' => [401, 502, 'unauthorized'],
]);

test('pausing while paused closes the earlier row and leaves exactly one open row', function () {
    $pluginUntil = now()->addMinutes(60)->timestamp;
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', $pluginUntil)));
    $project = hardeningProject();
    $earlier = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'paused_until' => now()->addMinutes(5)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertOk();

    expect($earlier->fresh()->resumed_at)->not->toBeNull();
    expect($earlier->fresh()->note)->toBe('Superseded by a new pause');

    $open = ProjectHardeningPause::open()->where('project_id', $project->id)->get();
    expect($open)->toHaveCount(1);
    expect($open->first()->id)->not->toBe($earlier->id);
    expect($open->first()->paused_until->timestamp)->toBe($pluginUntil);
});

test('a failed pause-while-paused reopens the row it superseded', function () {
    fakeHardeningPause(hardeningPluginBody(false, 'busy', hardeningPluginStatus('paused', now()->addMinutes(5)->timestamp)));
    $project = hardeningProject();
    $earlier = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'paused_until' => now()->addMinutes(5)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertStatus(409)
        ->assertJsonPath('open_pause.id', $earlier->id);

    expect(ProjectHardeningPause::count())->toBe(1);
    expect($earlier->fresh()->resumed_at)->toBeNull();
    expect($earlier->fresh()->note)->toBeNull();
});

test('an open row of another project is not superseded', function () {
    fakeHardeningPause(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addHour()->timestamp)));
    $project = hardeningProject();
    $foreign = ProjectHardeningPause::factory()->create();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 60])
        ->assertOk();

    expect($foreign->fresh()->resumed_at)->toBeNull();
});

test('a project without an LSM key leaves no row behind: nothing was sent, so nothing was paused', function () {
    Http::fake();
    $project = hardeningProject(['health_check_secret' => null]);
    $earlier = ProjectHardeningPause::factory()->create(['project_id' => $project->id]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/pause", ['minutes' => 15])
        ->assertStatus(502)
        ->assertJsonPath('reason', 'unreachable');

    expect(ProjectHardeningPause::count())->toBe(1);
    expect($earlier->fresh()->resumed_at)->toBeNull();
    Http::assertNothingSent();
});
