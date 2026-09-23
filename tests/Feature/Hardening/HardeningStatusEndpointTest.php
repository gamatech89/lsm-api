<?php

use App\Models\ProjectHardeningPause;
use Illuminate\Support\Facades\Http;

function fakeHardeningStatus(array $status): void
{
    Http::fake([
        '*/wp-json/lsm/v1/hardening/status*' => Http::response(hardeningPluginBody(true, null, $status, [], 'OK'), 200),
    ]);
}

test('a managing manager gets the full status shape with pause and enable allowed', function () {
    $status = hardeningPluginStatus('on');
    fakeHardeningStatus($status);
    $manager = hardeningUser('manager');
    $project = hardeningProject(['manager_id' => $manager->id]);

    $this->actingAs($manager)
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertExactJson([
            'reachable' => true,
            'plugin_outdated' => false,
            'min_version' => '2.10.0',
            'status' => $status,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => false],
        ]);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/wp-json/lsm/v1/hardening/status?_='));
});

test('admins by role and by is_admin flag are allowed everything', function (string $role, array $extra) {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser($role, $extra))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('can', ['pause' => true, 'enable' => true, 'disable' => true]);
})->with([
    'admin role' => ['admin', []],
    'manager with is_admin flag' => ['manager', ['is_admin' => true]],
]);

test('an assigned developer may only pause', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('can', ['pause' => true, 'enable' => false, 'disable' => false]);
});

test('an unassigned manager sees the status with every can flag false', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', true)
        ->assertJsonPath('status.rules.block_archives.state', 'on')
        ->assertJsonPath('can', ['pause' => false, 'enable' => false, 'disable' => false]);
});

test('a viewer is refused and the plugin is never called', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('viewer'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a guest gets 401', function () {
    $project = hardeningProject();

    $this->getJson("/api/v1/projects/{$project->id}/lsm/hardening")->assertUnauthorized();
});

test('a plugin older than 2.10.0 renders a quiet plugin_outdated state, still HTTP 200', function () {
    Http::fake(['*' => Http::response(['code' => 'rest_no_route'], 404)]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertExactJson([
            'reachable' => true,
            'plugin_outdated' => true,
            'min_version' => '2.10.0',
            'status' => null,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => true],
        ]);
});

test('an unreachable site renders reachable:false, still HTTP 200', function (string $failure) {
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
        'key rejected (401)' => Http::response(['code' => 'rest_forbidden'], 401),
        'html instead of json' => Http::response('<html>maintenance</html>', 200),
    }]);
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', false)
        ->assertJsonPath('plugin_outdated', false)
        ->assertJsonPath('status', null);
})->with(['connection failure', 'http 500', 'key rejected (401)', 'html instead of json']);

test('a project without an LSM key answers 200 reachable:false without calling anything', function () {
    Http::fake();
    $project = hardeningProject(['health_check_secret' => null]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', false);

    Http::assertNothingSent();
});

test('a running pause is reported as open_pause and is not overdue', function () {
    $until = now()->addMinutes(40);
    fakeHardeningStatus(hardeningPluginStatus('paused', $until->timestamp));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => $until,
        'created_at' => now()->subMinutes(20),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('open_pause.user_id', $pause->user_id)
        ->assertJsonPath('open_pause.paused_until', $pause->fresh()->paused_until->toJSON())
        ->assertJsonPath('pause_overdue', false);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('an open row past paused_until makes pause_overdue true even when the site is unreachable', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('reachable', false)
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('pause_overdue', true);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('the plugin own pause_overdue flag is passed on when the platform has no row', function () {
    fakeHardeningStatus(hardeningPluginStatus('paused', now()->subMinutes(3)->timestamp, true));
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', true);
});

test('an open row is closed opportunistically only when the plugin confirms the rule is on', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', false);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed from status: site no longer paused');
});

test('a status other than a confirmed on does not close the row', function (string $state) {
    fakeHardeningStatus(hardeningPluginStatus($state));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
})->with(['off', 'drift', 'manual']);

test('an unsupported rule whose pause_until is still set does not close the row', function () {
    fakeHardeningStatus(hardeningPluginStatus('unsupported', now()->subMinutes(5)->timestamp, true));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id)
        ->assertJsonPath('pause_overdue', true);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('a status that does not name a block_archives state closes nothing', function () {
    $status = hardeningPluginStatus('on');
    unset($status['rules']);
    fakeHardeningStatus($status);
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('a row younger than three minutes is left alone because its pause call may still be running', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create([
        'project_id' => $project->id,
        'paused_until' => now()->addHour(),
        'created_at' => now()->subSeconds(30),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('a pause row of another project is never touched or reported', function () {
    fakeHardeningStatus(hardeningPluginStatus('on'));
    $project = hardeningProject();
    $foreign = ProjectHardeningPause::factory()->create([
        'paused_until' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(65),
    ]);

    $this->actingAs(hardeningUser('admin'))
        ->getJson("/api/v1/projects/{$project->id}/lsm/hardening")
        ->assertOk()
        ->assertJsonPath('open_pause', null)
        ->assertJsonPath('pause_overdue', false);

    expect($foreign->fresh()->resumed_at)->toBeNull();
});
