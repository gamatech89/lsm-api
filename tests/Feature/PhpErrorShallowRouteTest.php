<?php

use App\Models\PhpError;
use App\Models\Project;
use App\Models\User;

function makePhpError(Project $project): PhpError
{
    return $project->phpErrors()->create([
        'type' => 'warning',
        'message' => 'Undefined variable $x',
        'file' => '/wp-content/themes/x/functions.php',
        'line' => 42,
        'error_hash' => md5(uniqid('', true)),
        'count' => 1,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

test('admin can view a single php error (shallow route resolves project)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $error = makePhpError(Project::factory()->create());

    $response = $this->actingAs($admin)->getJson("/api/v1/php-errors/{$error->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $error->id);
});

test('admin can resolve a php error', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $error = makePhpError(Project::factory()->create());

    $response = $this->actingAs($admin)->postJson("/api/v1/php-errors/{$error->id}/resolve");

    $response->assertOk();
    expect($error->fresh()->is_resolved)->toBeTrue();
});

test('admin can delete a php error', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $error = makePhpError(Project::factory()->create());

    $response = $this->actingAs($admin)->deleteJson("/api/v1/php-errors/{$error->id}");

    $response->assertOk();
    $response->assertJsonPath('message', 'Error deleted');
});

test('a developer cannot view a php error on a project they are not on', function () {
    $developer = User::factory()->create(['role' => 'developer']);
    $error = makePhpError(Project::factory()->create());

    $response = $this->actingAs($developer)->getJson("/api/v1/php-errors/{$error->id}");

    $response->assertStatus(403);
});
