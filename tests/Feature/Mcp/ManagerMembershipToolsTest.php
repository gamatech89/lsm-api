<?php

use App\Mcp\Servers\LsmServer;
use App\Mcp\Tools\BulkAssignManagersTool;
use App\Mcp\Tools\CompleteTodoTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;

/**
 * MCP-tool pins for the isManagedBy() sweep: manager membership must accept
 * BOTH the legacy projects.manager_id column and the project_manager pivot,
 * membership alone must never bypass the role gate, and every assign path
 * must keep the legacy column and the pivot in lockstep.
 */

// ---------------------------------------------------------------------------
// CompleteTodoTool — pivot-only manager must be allowed (mirror of the
// legacy-only bug: the tool used to check manager_id === $user->id only)
// ---------------------------------------------------------------------------

test('complete-todo: a pivot-only manager can complete a todo in their project', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    // Pivot row only — deliberately NO legacy manager_id
    $project = Project::factory()->create(['manager_id' => null]);
    $project->managers()->attach($manager->id);

    $todo = Todo::factory()->create(['project_id' => $project->id, 'status' => 'pending']);

    LsmServer::actingAs(actingWithScopes($manager, ['*']))
        ->tool(CompleteTodoTool::class, ['todo_id' => $todo->id])
        ->assertOk()
        ->assertSee('Todo Completed');

    expect($todo->refresh()->status)->toBe('completed');
});

test('complete-todo: a legacy-only manager can complete a todo in their project', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    // Legacy column only — deliberately NO pivot row
    $project = Project::factory()->create(['manager_id' => $manager->id]);

    $todo = Todo::factory()->create(['project_id' => $project->id, 'status' => 'pending']);

    LsmServer::actingAs(actingWithScopes($manager, ['*']))
        ->tool(CompleteTodoTool::class, ['todo_id' => $todo->id])
        ->assertOk();

    expect($todo->refresh()->status)->toBe('completed');
});

test('complete-todo: an unrelated manager is denied', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $stranger = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create(['manager_id' => $manager->id]);

    $todo = Todo::factory()->create(['project_id' => $project->id, 'status' => 'pending']);

    LsmServer::actingAs(actingWithScopes($stranger, ['*']))
        ->tool(CompleteTodoTool::class, ['todo_id' => $todo->id])
        ->assertHasErrors(['You do not have permission to complete this todo.']);

    expect($todo->refresh()->status)->toBe('pending');
});

// ---------------------------------------------------------------------------
// UpdateProjectTool — membership without the manager role must be denied
// (a demoted user with a stale legacy manager_id keeps no write access)
// ---------------------------------------------------------------------------

test('update-project: a demoted developer with a stale legacy manager_id is denied', function () {
    $demoted = User::factory()->create(['role' => 'developer']);
    // Stale legacy pointer to a user who is no longer a manager
    $project = Project::factory()->create(['manager_id' => $demoted->id]);
    $originalName = $project->name;

    LsmServer::actingAs(actingWithScopes($demoted, ['*']))
        ->tool(UpdateProjectTool::class, [
            'project_id' => $project->id,
            'name' => 'Hijacked Name',
        ])
        ->assertHasErrors(['Only admins and project managers can update projects.']);

    expect($project->refresh()->name)->toBe($originalName);
});

test('update-project: a pivot-only manager can update the project', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create(['manager_id' => null]);
    $project->managers()->attach($manager->id);

    LsmServer::actingAs(actingWithScopes($manager, ['*']))
        ->tool(UpdateProjectTool::class, [
            'project_id' => $project->id,
            'name' => 'Renamed By Pivot Manager',
        ])
        ->assertOk();

    expect($project->refresh()->name)->toBe('Renamed By Pivot Manager');
});

test('update-project: a legacy-only manager can update the project', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create(['manager_id' => $manager->id]);

    LsmServer::actingAs(actingWithScopes($manager, ['*']))
        ->tool(UpdateProjectTool::class, [
            'project_id' => $project->id,
            'name' => 'Renamed By Legacy Manager',
        ])
        ->assertOk();

    expect($project->refresh()->name)->toBe('Renamed By Legacy Manager');
});

// ---------------------------------------------------------------------------
// BulkAssignManagersTool — assign paths must sync the pivot alongside the
// legacy column, and the by-id lookup must reject non-manager roles
// ---------------------------------------------------------------------------

test('bulk-assign-managers: round-robin assign keeps legacy manager_id and pivot in lockstep', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager']);
    $projectA = Project::factory()->create(['manager_id' => null]);
    $projectB = Project::factory()->create(['manager_id' => null]);

    LsmServer::actingAs(actingWithScopes($admin, ['*']))
        ->tool(BulkAssignManagersTool::class, [
            'action' => 'assign',
            'mode' => 'specific',
            'project_ids' => [$projectA->id, $projectB->id],
            'manager_ids' => [$manager->id],
            'strategy' => 'round_robin',
        ])
        ->assertOk()
        ->assertSee('Bulk PM Assignment Complete');

    foreach ([$projectA, $projectB] as $project) {
        $project->refresh();
        expect($project->manager_id)->toBe($manager->id);
        expect($project->managers()->pluck('user_id')->all())->toBe([$manager->id]);
    }
});

test('bulk-assign-managers: weighted assign keeps legacy manager_id and pivot in lockstep', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $managerA = User::factory()->create(['role' => 'manager']);
    $managerB = User::factory()->create(['role' => 'manager']);
    $projectA = Project::factory()->create(['manager_id' => null]);
    $projectB = Project::factory()->create(['manager_id' => null]);

    LsmServer::actingAs(actingWithScopes($admin, ['*']))
        ->tool(BulkAssignManagersTool::class, [
            'action' => 'assign',
            'mode' => 'specific',
            'project_ids' => [$projectA->id, $projectB->id],
            'manager_ids' => [$managerA->id, $managerB->id],
            'strategy' => 'weighted',
            'weights' => [50, 50],
        ])
        ->assertOk();

    foreach ([$projectA, $projectB] as $project) {
        $project->refresh();
        expect($project->manager_id)->not->toBeNull();
        expect($project->managers()->pluck('user_id')->all())->toBe([$project->manager_id]);
    }
});

test('bulk-assign-managers: random assign keeps legacy manager_id and pivot in lockstep', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager']);
    $project = Project::factory()->create(['manager_id' => null]);

    LsmServer::actingAs(actingWithScopes($admin, ['*']))
        ->tool(BulkAssignManagersTool::class, [
            'action' => 'assign',
            'mode' => 'specific',
            'project_ids' => [$project->id],
            'manager_ids' => [$manager->id],
            'strategy' => 'random',
        ])
        ->assertOk();

    $project->refresh();
    expect($project->manager_id)->toBe($manager->id);
    expect($project->managers()->pluck('user_id')->all())->toBe([$manager->id]);
});

test('bulk-assign-managers: by-id lookup rejects users who are not admins or managers', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $developer = User::factory()->create(['role' => 'developer']);
    $project = Project::factory()->create(['manager_id' => null]);

    LsmServer::actingAs($admin)
        ->tool(BulkAssignManagersTool::class, [
            'action' => 'assign',
            'mode' => 'specific',
            'project_ids' => [$project->id],
            'manager_ids' => [$developer->id],
        ])
        ->assertHasErrors(["not admins or managers (or do not exist): {$developer->id}"]);

    $project->refresh();
    expect($project->manager_id)->toBeNull();
    expect($project->managers()->count())->toBe(0);
});

test('bulk-assign-managers: by-id lookup accepts admin-role users', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $adminManager = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create(['manager_id' => null]);

    LsmServer::actingAs($admin)
        ->tool(BulkAssignManagersTool::class, [
            'action' => 'assign',
            'mode' => 'specific',
            'project_ids' => [$project->id],
            'manager_ids' => [$adminManager->id],
        ])
        ->assertOk();

    $project->refresh();
    expect($project->manager_id)->toBe($adminManager->id);
    expect($project->managers()->pluck('user_id')->all())->toBe([$adminManager->id]);
});
