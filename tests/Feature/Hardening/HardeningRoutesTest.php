<?php

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\RejectIntegrationTokens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

test('the four hardening routes are registered with the expected verb, uri and middleware', function (string $name, string $verb, string $uri, bool $throttled) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull();
    expect($route->methods())->toContain($verb);
    expect($route->uri())->toBe($uri);

    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('auth:sanctum', RejectIntegrationTokens::class, EnsureTwoFactorEnrolled::class);
    expect(in_array('throttle:12,1,hardening', $middleware, true))->toBe($throttled);
})->with([
    ['api.v1.projects.lsm.hardening', 'GET', 'api/v1/projects/{project}/lsm/hardening', false],
    ['api.v1.projects.lsm.hardening.rule', 'POST', 'api/v1/projects/{project}/lsm/hardening/rule', true],
    ['api.v1.projects.lsm.hardening.pause', 'POST', 'api/v1/projects/{project}/lsm/hardening/pause', true],
    ['api.v1.projects.lsm.hardening.resume', 'POST', 'api/v1/projects/{project}/lsm/hardening/resume', true],
]);

test('the 13th hardening POST within a minute is throttled, across the three POST routes', function () {
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();
    $base = "/api/v1/projects/{$project->id}/lsm/hardening";

    for ($i = 0; $i < 4; $i++) {
        $this->actingAs($admin)->postJson("{$base}/rule", ['rule' => 'block_debug_log', 'enabled' => true])->assertOk();
        $this->actingAs($admin)->postJson("{$base}/pause", ['minutes' => 15])->assertOk();
        $this->actingAs($admin)->postJson("{$base}/resume")->assertOk();
    }

    $this->actingAs($admin)->postJson("{$base}/resume")->assertStatus(429);
});

test('the status GET is not throttled', function () {
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    for ($i = 0; $i < 15; $i++) {
        $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}/lsm/hardening")->assertOk();
    }
});

test('the hardening counter is its own bucket: other throttled routes do not use it up', function () {
    Http::fake(['*' => Http::response(hardeningPluginBody(true, null, hardeningPluginStatus('on')), 200)]);
    $admin = hardeningUser('admin');
    $project = hardeningProject();

    // /search is behind throttle:30,1. Unprefixed throttles share one per-user
    // counter, so without the `hardening` prefix these 13 hits would already
    // exceed the hardening limit of 12.
    for ($i = 0; $i < 13; $i++) {
        $this->actingAs($admin)->getJson('/api/v1/search?q=client')->assertOk();
    }

    $this->actingAs($admin)
        ->postJson("/api/v1/projects/{$project->id}/lsm/hardening/resume")
        ->assertOk();
});
