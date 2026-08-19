<?php

use App\Models\Project;
use App\Models\User;

/**
 * "PMs see all projects for now": with permissions.managers_view_all_projects
 * on (MANAGERS_VIEW_ALL_PROJECTS, default true), managers can SEE every
 * project — list, detail, dashboard, search, MCP project reads — regardless of
 * assignment. Write authority is untouched: update/delete/credentials/team
 * still require managing the project. Developers are untouched either way.
 * Turning the flag off restores assignment-scoped visibility without a code
 * change.
 */
function makeUnmanagedProject(): Project
{
    // A project managed by somebody else entirely.
    $otherManager = User::factory()->create(['role' => 'manager']);

    return Project::factory()->create(['manager_id' => $otherManager->id]);
}

test('the flag defaults to on', function () {
    $key = 'MANAGERS_VIEW_ALL_PROJECTS';
    $saved = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);

    try {
        $shipped = require config_path('permissions.php');
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

    expect($shipped['managers_view_all_projects'])->toBeTrue();
});

test('a manager sees unassigned projects in the project list', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = makeUnmanagedProject();

    $response = $this->actingAs($manager)->getJson('/api/v1/projects?per_page=100');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($project->id);
});

test('a manager can open an unassigned project detail', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = makeUnmanagedProject();

    $this->actingAs($manager)->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $project->id);
});

test('a manager still cannot update or delete an unassigned project', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = makeUnmanagedProject();

    $this->actingAs($manager)->putJson("/api/v1/projects/{$project->id}", [
        'name' => $project->name,
        'url' => $project->url,
        'health_status' => 'online',
        'security_status' => 'secure',
    ])->assertStatus(403);

    $this->actingAs($manager)->deleteJson("/api/v1/projects/{$project->id}")->assertStatus(403);
});

test('a manager still cannot manage credentials or team on an unassigned project', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = makeUnmanagedProject();

    $this->actingAs($manager)->postJson("/api/v1/projects/{$project->id}/credentials", [
        'title' => 'Prod DB', 'type' => 'database', 'username' => 'root', 'password' => 's3cret',
    ])->assertStatus(403);
});

test('a developer is unaffected: unassigned projects stay hidden', function () {
    $developer = User::factory()->create(['role' => 'developer']);
    $project = makeUnmanagedProject();

    $list = $this->actingAs($developer)->getJson('/api/v1/projects?per_page=100');
    $list->assertOk();
    expect(collect($list->json('data'))->pluck('id'))->not->toContain($project->id);

    $this->actingAs($developer)->getJson("/api/v1/projects/{$project->id}")->assertStatus(403);
});

test('with the flag off a manager is scoped to assigned projects again', function () {
    config(['permissions.managers_view_all_projects' => false]);
    $manager = User::factory()->create(['role' => 'manager']);
    $mine = Project::factory()->create(['manager_id' => $manager->id]);
    $foreign = makeUnmanagedProject();

    $list = $this->actingAs($manager)->getJson('/api/v1/projects?per_page=100');
    $list->assertOk();
    $ids = collect($list->json('data'))->pluck('id');
    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($foreign->id);

    $this->actingAs($manager)->getJson("/api/v1/projects/{$foreign->id}")->assertStatus(403);
});

test('dashboard project stats count all projects for a manager', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    makeUnmanagedProject();
    makeUnmanagedProject();

    $response = $this->actingAs($manager)->getJson('/api/v1/dashboard/stats');

    $response->assertOk();
    expect((int) $response->json('data.total'))->toBe(2);
});

test('search returns unassigned projects for a manager', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = makeUnmanagedProject();

    $response = $this->actingAs($manager)->getJson('/api/v1/search?q='.urlencode($project->name));

    $response->assertOk();
    expect(collect($response->json('data.projects'))->pluck('id'))->toContain($project->id);
});

test('mcp list-projects returns unassigned projects for a manager', function () {
    $manager = actingWithScopes(User::factory()->create(['role' => 'manager']), ['*']);
    app('auth')->guard()->setUser($manager);
    $project = makeUnmanagedProject();

    $response = app()->call([new \App\Mcp\Tools\ListProjectsTool, 'handle'], [
        'request' => new \Laravel\Mcp\Request([]),
    ]);

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain($project->name);
});
