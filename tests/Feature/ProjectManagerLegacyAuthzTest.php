<?php

use App\Models\Project;
use App\Models\User;

function validCredentialPayload(): array
{
    return [
        'title' => 'Prod DB',
        'type' => 'database',
        'username' => 'root',
        'password' => 's3cret',
    ];
}

function validProjectPayload(Project $project): array
{
    return [
        'name' => $project->name,
        'url' => $project->url,
        'health_status' => 'online',
        'security_status' => 'secure',
    ];
}

test('a manager assigned only via legacy manager_id can create credentials', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    // Legacy column only — NO project_manager pivot row
    $project = Project::factory()->create(['manager_id' => $manager->id]);

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/credentials", validCredentialPayload())
        ->assertStatus(201);

    $this->assertDatabaseHas('credentials', [
        'project_id' => $project->id,
        'title' => 'Prod DB',
        'type' => 'database',
    ]);
});

test('a manager assigned only via legacy manager_id can update the project', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create(['manager_id' => $manager->id]);

    $this->actingAs($manager)
        ->putJson("/api/v1/projects/{$project->id}", validProjectPayload($project))
        ->assertOk();
});

test('a manager assigned via the pivot can still create credentials', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create();
    $project->managers()->attach($manager->id);

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/credentials", validCredentialPayload())
        ->assertStatus(201);

    $this->assertDatabaseHas('credentials', [
        'project_id' => $project->id,
        'title' => 'Prod DB',
        'type' => 'database',
    ]);
});

test('a developer can never create credentials', function () {
    $developer = User::factory()->create(['role' => 'developer']);
    $project = Project::factory()->create(['developer_id' => $developer->id]);
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)
        ->postJson("/api/v1/projects/{$project->id}/credentials", validCredentialPayload())
        ->assertStatus(403);
});

test('a manager on neither the legacy column nor the pivot gets 403', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create();

    $this->actingAs($manager)
        ->postJson("/api/v1/projects/{$project->id}/credentials", validCredentialPayload())
        ->assertStatus(403);
});
