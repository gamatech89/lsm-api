<?php

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;

function makeProjectWithTodo(): array
{
    $project = Project::factory()->create();
    $todo = Todo::factory()->create([
        'project_id' => $project->id,
        'status' => 'in_progress',
        'priority' => 'medium',
    ]);

    return [$project, $todo];
}

test('a developer on the project pivot can move a todo to in_review', function () {
    [$project, $todo] = makeProjectWithTodo();
    $developer = User::factory()->create(['role' => 'developer']);
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)->putJson("/api/v1/todos/{$todo->id}", [
        'status' => 'in_review',
    ])->assertOk();

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'status' => 'in_review',
    ]);
});

test('a developer sending completed is coerced to in_review', function () {
    [$project, $todo] = makeProjectWithTodo();
    $developer = User::factory()->create(['role' => 'developer']);
    $project->developers()->attach($developer->id);

    // Developers cannot complete todos directly; the controller downgrades
    // 'completed' to 'in_review' (validation also rejects it outright, so the
    // coercion path is exercised via the legacy `completed` boolean).
    $this->actingAs($developer)->putJson("/api/v1/todos/{$todo->id}", [
        'completed' => true,
    ])->assertOk();

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'status' => 'in_review',
    ]);
});

test('a developer sending status=completed directly gets 422 and the status is unchanged', function () {
    [$project, $todo] = makeProjectWithTodo();
    $developer = User::factory()->create(['role' => 'developer']);
    $project->developers()->attach($developer->id);

    $this->actingAs($developer)->putJson("/api/v1/todos/{$todo->id}", [
        'status' => 'completed',
    ])->assertStatus(422);

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'status' => 'in_progress',
    ]);
});

test('an admin can still mark a todo completed', function () {
    [$project, $todo] = makeProjectWithTodo();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->putJson("/api/v1/todos/{$todo->id}", [
        'status' => 'completed',
    ])->assertOk();

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'status' => 'completed',
    ]);
});

test('a manager on the project pivot can still mark a todo completed', function () {
    [$project, $todo] = makeProjectWithTodo();
    $manager = User::factory()->create(['role' => 'manager']);
    $project->managers()->attach($manager->id);

    $this->actingAs($manager)->putJson("/api/v1/todos/{$todo->id}", [
        'status' => 'completed',
    ])->assertOk();

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'status' => 'completed',
    ]);
});
