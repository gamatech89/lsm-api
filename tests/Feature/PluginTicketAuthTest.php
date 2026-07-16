<?php
// tests/Feature/PluginTicketAuthTest.php

use App\Http\Middleware\AuthenticateLsmPlugin;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(AuthenticateLsmPlugin::class)->get('/_test/plugin-auth', function (\Illuminate\Http\Request $request) {
        return response()->json(['project_id' => $request->attributes->get('lsm_project')->id]);
    });
});

test('request without an API key is rejected with 401', function () {
    $this->getJson('/_test/plugin-auth')->assertStatus(401);
});

test('request with an unknown API key is rejected with 401', function () {
    Project::factory()->create(['health_check_secret' => 'REAL_KEY']);

    $this->getJson('/_test/plugin-auth', ['X-LSM-Key' => 'WRONG_KEY'])->assertStatus(401);
});

test('request with a valid API key resolves the owning project', function () {
    $project = Project::factory()->create(['health_check_secret' => 'REAL_KEY_42']);

    $this->getJson('/_test/plugin-auth', ['X-LSM-Key' => 'REAL_KEY_42'])
        ->assertOk()
        ->assertJson(['project_id' => $project->id]);
});
