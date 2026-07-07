<?php

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('project index does not issue a todos query per project (no N+1)', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    foreach (range(1, 4) as $i) {
        $project = Project::factory()->create();
        Todo::create([
            'project_id' => $project->id,
            'title' => "Task {$i}",
            'priority' => 'high',
            'status' => 'pending',
        ]);
    }

    DB::enableQueryLog();
    $this->actingAs($admin)->getJson('/api/v1/projects')->assertOk();
    $queries = DB::getQueryLog();

    // Separate SELECTs against the todos table (the eager load is one; the N+1
    // would be one per project). withCount uses a subquery, not a separate query.
    $todoSelects = collect($queries)
        ->filter(fn ($q) => preg_match('/^select \* from [`"]todos[`"]/i', $q['query']))
        ->count();

    expect($todoSelects)->toBeLessThanOrEqual(1);
});

test('highest_todo_priority is still returned correctly', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create();
    Todo::create(['project_id' => $project->id, 'title' => 'a', 'priority' => 'low', 'status' => 'pending']);
    Todo::create(['project_id' => $project->id, 'title' => 'b', 'priority' => 'urgent', 'status' => 'pending']);

    $response = $this->actingAs($admin)->getJson('/api/v1/projects');

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $project->id);
    expect($row['highest_todo_priority'])->toBe('urgent');
});
