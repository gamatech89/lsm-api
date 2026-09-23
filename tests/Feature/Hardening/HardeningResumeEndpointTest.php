<?php

use App\Models\ProjectHardeningPause;
use Illuminate\Support\Facades\Http;

function fakeHardeningResume(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/resume' => Http::response($body, $http)]);
}

test('an assigned developer can resume and the plugin gets a POST with a 120 s timeout', function () {
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200);
    });
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status.rules.block_archives.state', 'on');

    expect($seenTimeout)->toBe(120);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/resume');
});

test('an unassigned manager cannot resume: 403 and nothing sent', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a successful resume closes the open row, even a fresh one', function () {
    fakeHardeningResume(hardeningPluginBody(true, null, hardeningPluginStatus('on')));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'created_at' => now()->subSeconds(20)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', false);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed: resumed from the platform');
});

test('resume is an idempotent no-op when nothing is paused', function () {
    fakeHardeningResume(hardeningPluginBody(true, null, hardeningPluginStatus('on'), [], 'Nothing to resume'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk()
        ->assertJsonPath('message', 'Nothing to resume');

    expect(ProjectHardeningPause::count())->toBe(0);
});

test('a resume that did not succeed leaves the row open and reports it', function (string $case, int $expectedHttp, string $reason) {
    Http::fake(['*' => match ($case) {
        'explicit failure' => Http::response(hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', now()->subMinutes(5)->timestamp, true)), 200),
        'busy' => Http::response(hardeningPluginBody(false, 'busy', hardeningPluginStatus('paused', now()->subMinutes(5)->timestamp, true)), 200),
        'unreachable' => Http::failedConnection(),
        'old plugin' => Http::response(['code' => 'rest_no_route'], 404),
        'key rejected' => Http::response(['code' => 'rest_forbidden'], 403),
    }]);
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertStatus($expectedHttp)
        ->assertJsonPath('success', false)
        ->assertJsonPath('reason', $reason);

    expect($pause->fresh()->resumed_at)->toBeNull();
    expect($pause->fresh()->failed_at)->toBeNull();
})->with([
    ['explicit failure', 422, 'asset_broken'],
    ['busy', 409, 'busy'],
    ['unreachable', 502, 'unreachable'],
    ['old plugin', 409, 'plugin_outdated'],
    ['key rejected', 502, 'unauthorized'],
]);

test('a failed resume still tells the panel that the pause is overdue', function () {
    fakeHardeningResume(hardeningPluginBody(false, 'asset_broken', hardeningPluginStatus('paused', now()->subMinutes(5)->timestamp, true)));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertStatus(422)
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('pause_overdue', true);
});
