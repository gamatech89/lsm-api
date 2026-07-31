<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Pins the legacy manager_id <-> project_manager pivot synchronisation in
 * ProjectController store()/update().
 */

test('store: manager_ids wins over an explicitly supplied divergent manager_id', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $managerA = User::factory()->create(['role' => 'manager']);
    $managerB = User::factory()->create(['role' => 'manager']);

    $response = $this->actingAs($admin)->postJson('/api/v1/projects', [
        'name' => 'Sync Divergence Test',
        'url' => 'https://sync-divergence-test.example.com',
        'manager_id' => $managerA->id,
        'manager_ids' => [$managerB->id],
    ]);

    $response->assertStatus(201);

    $project = Project::where('name', 'Sync Divergence Test')->firstOrFail();

    // Legacy column mirrors manager_ids[0], NOT the divergent manager_id
    expect($project->manager_id)->toBe($managerB->id);
    expect($project->managers()->pluck('user_id')->all())->toBe([$managerB->id]);
});

test('store: a keyed manager_ids array still mirrors the first manager into the legacy column', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager']);

    // Keyed (non 0-indexed) array — $managerIds[0] would silently be null
    // without array_values() normalisation
    $response = $this->actingAs($admin)->postJson('/api/v1/projects', [
        'name' => 'Keyed Manager Ids Store Test',
        'url' => 'https://keyed-manager-ids-store.example.com',
        'manager_ids' => [2 => $manager->id],
    ]);

    $response->assertStatus(201);

    $project = Project::where('name', 'Keyed Manager Ids Store Test')->firstOrFail();

    expect($project->manager_id)->toBe($manager->id);
    expect($project->managers()->pluck('user_id')->all())->toBe([$manager->id]);
});

test('update: a keyed manager_ids array still mirrors the first manager into the legacy column', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $managerA = User::factory()->create(['role' => 'manager']);
    $managerB = User::factory()->create(['role' => 'manager']);

    $project = Project::factory()->create(['manager_id' => $managerA->id]);
    $project->managers()->attach($managerA->id);

    // Keyed (non 0-indexed) array — $managerIds[0] would silently be null
    // without array_values() normalisation
    $this->actingAs($admin)->putJson("/api/v1/projects/{$project->id}", [
        'manager_ids' => [3 => $managerB->id],
    ])->assertOk();

    $project->refresh();

    expect($project->manager_id)->toBe($managerB->id);
    expect($project->managers()->pluck('user_id')->all())->toBe([$managerB->id]);
});

test('update: manager_id=null clears the legacy column AND detaches the pivot', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager']);

    $project = Project::factory()->create(['manager_id' => $manager->id]);
    $project->managers()->attach($manager->id);

    $this->actingAs($admin)->putJson("/api/v1/projects/{$project->id}", [
        'manager_id' => null,
    ])->assertOk();

    $project->refresh();

    expect($project->manager_id)->toBeNull();
    $this->assertDatabaseMissing('project_manager', [
        'project_id' => $project->id,
        'user_id' => $manager->id,
    ]);
    expect($project->managers()->count())->toBe(0);
});
