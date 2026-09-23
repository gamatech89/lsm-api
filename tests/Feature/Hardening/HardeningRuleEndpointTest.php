<?php

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeHardeningRule(array $body, int $http = 200): void
{
    Http::fake(['*/wp-json/lsm/v1/hardening/rule' => Http::response($body, $http)]);
}

/** @return array{0: User, 1: Project} */
function managedHardeningProject(): array
{
    $manager = hardeningUser('manager');

    return [$manager, hardeningProject(['manager_id' => $manager->id])];
}

// ---------------------------------------------------------------------------
// Authorization: direction-dependent, before validation, fail closed
// ---------------------------------------------------------------------------

test('a managing manager can turn a rule on and the plugin receives real booleans with a 120 s timeout', function () {
    $seenTimeout = null;
    Http::fake(function ($request, array $options) use (&$seenTimeout) {
        $seenTimeout = $options['timeout'] ?? null;

        return Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200);
    });
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertOk();

    expect($seenTimeout)->toBe(120);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://client.example.com/wp-json/lsm/v1/hardening/rule'
        && $request['rule'] === 'block_archives'
        && $request['enabled'] === true);
});

test('a managing manager cannot turn a rule off', function () {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('an assigned developer cannot turn a rule on', function () {
    Http::fake();
    $developer = hardeningUser('developer');
    $project = hardeningProject();
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('an unassigned manager cannot turn a rule on', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('manager'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('admins by role and by is_admin flag can turn a rule off', function (string $role, array $extra) {
    fakeHardeningRule(hardeningPluginBody(true, null, hardeningPluginStatus('off')));
    $project = hardeningProject();

    $this->actingAs(hardeningUser($role, $extra))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_debug_log', 'enabled' => false])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['rule'] === 'block_debug_log' && $request['enabled'] === false);
})->with([
    'admin role' => ['admin', []],
    'manager with is_admin flag' => ['manager', ['is_admin' => true]],
]);

test('a missing or garbage enabled counts as a disable: 403 for a manager, never a 422', function (array $payload) {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", $payload)
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'enabled missing' => [['rule' => 'block_archives']],
    'enabled garbage' => [['rule' => 'block_archives', 'enabled' => 'banana']],
    'enabled null' => [['rule' => 'block_archives', 'enabled' => null]],
    'rule also invalid' => [['rule' => 'nope']],
]);

test('an admin with a missing enabled gets the validation 422', function () {
    Http::fake();
    $project = hardeningProject();

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['enabled']);

    Http::assertNothingSent();
});

test('a truthy string passes the enable gate but is rejected by validation before anything is sent', function () {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => 'yes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['enabled']);

    Http::assertNothingSent();
});

test('an unknown rule key is rejected by validation before anything is sent', function () {
    Http::fake();
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_everything', 'enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rule']);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Response mapping, one test per row of the spec table
// ---------------------------------------------------------------------------

test('mapping row 1: plugin success becomes 200 with status and the platform keys', function () {
    $status = hardeningPluginStatus('on');
    fakeHardeningRule(hardeningPluginBody(true, null, $status, ['already_blocked_elsewhere']));
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Applied and verified',
            'warnings' => ['already_blocked_elsewhere'],
            'status' => $status,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => false],
        ]);
});

test('mapping row 2: plugin busy becomes 409 with reason busy, status and the platform keys', function () {
    $status = hardeningPluginStatus('off');
    fakeHardeningRule(hardeningPluginBody(false, 'busy', $status, [], 'Another hardening operation is running'));
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(409)
        ->assertExactJson([
            'success' => false,
            'reason' => 'busy',
            'message' => 'Another hardening operation is running',
            'warnings' => [],
            'status' => $status,
            'open_pause' => null,
            'pause_overdue' => false,
            'can' => ['pause' => true, 'enable' => true, 'disable' => false],
        ]);
});

test('mapping row 3: any other plugin failure becomes 422 with the plugin reason and no validation errors key', function () {
    $status = hardeningPluginStatus('off');
    fakeHardeningRule(hardeningPluginBody(false, 'rule_ineffective', $status, [], 'The .zip probe was served by a front-end nginx'));
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('reason', 'rule_ineffective')
        ->assertJsonPath('message', 'The .zip probe was served by a front-end nginx')
        ->assertJsonPath('status', $status)
        ->assertJsonPath('can.enable', true)
        ->assertJsonMissingPath('errors');
});

test('mapping row 4: a 404 from the plugin becomes 409 plugin_outdated', function () {
    fakeHardeningRule(['code' => 'rest_no_route'], 404);
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(409)
        ->assertExactJson([
            'success' => false,
            'reason' => 'plugin_outdated',
            'min_version' => '2.10.0',
            'message' => 'Requires plugin 2.10.0 or newer — update the plugin',
        ]);
});

test('mapping row 5: a 401 or 403 from the plugin becomes 502 unauthorized', function (int $http) {
    fakeHardeningRule(['code' => 'rest_forbidden'], $http);
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(502)
        ->assertExactJson([
            'success' => false,
            'reason' => 'unauthorized',
            'message' => 'The site rejected the platform API key',
        ]);
})->with([401, 403]);

test('mapping row 6: no response, a timeout or a 5xx becomes 502 unreachable', function (string $failure) {
    Http::fake(['*' => match ($failure) {
        'connection failure' => Http::failedConnection(),
        'http 500' => Http::response('server error', 500),
        'http 504' => Http::response('gateway timeout', 504),
    }]);
    [$manager, $project] = managedHardeningProject();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => true])
        ->assertStatus(502)
        ->assertExactJson([
            'success' => false,
            'reason' => 'unreachable',
            'message' => 'Outcome unknown — refresh status',
        ]);
})->with(['connection failure', 'http 500', 'http 504']);

// ---------------------------------------------------------------------------
// Pause rows
// ---------------------------------------------------------------------------

test('turning block_archives off closes the open pause row, even a fresh one', function () {
    fakeHardeningRule(hardeningPluginBody(true, null, hardeningPluginStatus('off')));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id, 'created_at' => now()->subSeconds(20)]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertOk()
        ->assertJsonPath('open_pause', null);

    expect($pause->fresh()->resumed_at)->not->toBeNull();
    expect($pause->fresh()->note)->toBe('Closed: block_archives turned off');
});

test('a failed attempt to turn block_archives off leaves the pause row open', function () {
    fakeHardeningRule(hardeningPluginBody(false, 'loopback_blocked', hardeningPluginStatus('paused', now()->addMinutes(10)->timestamp)));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_archives', 'enabled' => false])
        ->assertStatus(422)
        ->assertJsonPath('open_pause.id', $pause->id);

    expect($pause->fresh()->resumed_at)->toBeNull();
});

test('turning a different rule off leaves the pause row open', function () {
    fakeHardeningRule(hardeningPluginBody(true, null, hardeningPluginStatus('paused', now()->addMinutes(10)->timestamp)));
    $project = hardeningProject();
    $pause = ProjectHardeningPause::factory()->create(['project_id' => $project->id]);

    $this->actingAs(hardeningUser('admin'))
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/rule", ['rule' => 'block_debug_log', 'enabled' => false])
        ->assertOk();

    expect($pause->fresh()->resumed_at)->toBeNull();
});
