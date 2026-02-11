<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    // Create users with different roles
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->manager = User::factory()->create(['role' => 'manager']);
    $this->developer = User::factory()->create(['role' => 'developer']);
    $this->otherDeveloper = User::factory()->create(['role' => 'developer']);
    
    // Create a project assigned to manager and developer
    $this->project = Project::factory()->create([
        'manager_id' => $this->manager->id,
        'developer_id' => $this->developer->id,
    ]);
    
    // Create a timesheet for the developer
    $this->timesheet = Timesheet::getOrCreateForWeek($this->developer->id);
    
    // Create a time entry for the developer
    $this->timeEntry = TimeEntry::create([
        'user_id' => $this->developer->id,
        'project_id' => $this->project->id,
        'timesheet_id' => $this->timesheet->id,
        'description' => 'Test work',
        'started_at' => Carbon::now()->subHours(2),
        'ended_at' => Carbon::now()->subHour(),
        'duration_minutes' => 60,
        'is_billable' => true,
        'status' => TimeEntry::STATUS_DRAFT,
    ]);
});

test('developer can view their own time entries', function () {
    $response = $this->actingAs($this->developer)->getJson('/api/v1/time-entries');
    
    $response->assertOk();
    $response->assertJsonStructure(['data']);
});

test('developer can create time entry', function () {
    $response = $this->actingAs($this->developer)->postJson('/api/v1/time-entries', [
        'project_id' => $this->project->id,
        'description' => 'New task work',
        'started_at' => Carbon::now()->subHours(3)->toDateTimeString(),
        'ended_at' => Carbon::now()->subHours(2)->toDateTimeString(),
        'is_billable' => true,
    ]);
    
    $response->assertStatus(201);
    $this->assertDatabaseHas('time_entries', ['description' => 'New task work']);
});

test('developer can update their own draft entry', function () {
    $response = $this->actingAs($this->developer)->putJson(
        "/api/v1/time-entries/{$this->timeEntry->id}",
        ['description' => 'Updated description']
    );
    
    $response->assertOk();
    $this->timeEntry->refresh();
    expect($this->timeEntry->description)->toBe('Updated description');
});

test('developer cannot update submitted entry', function () {
    $this->timeEntry->update(['status' => TimeEntry::STATUS_SUBMITTED]);
    
    $response = $this->actingAs($this->developer)->putJson(
        "/api/v1/time-entries/{$this->timeEntry->id}",
        ['description' => 'Should fail']
    );
    
    $response->assertStatus(400);
});

test('developer cannot update another users entry', function () {
    $response = $this->actingAs($this->otherDeveloper)->putJson(
        "/api/v1/time-entries/{$this->timeEntry->id}",
        ['description' => 'Should fail']
    );
    
    $response->assertForbidden();
});

test('developer can delete their own draft entry', function () {
    $response = $this->actingAs($this->developer)->deleteJson(
        "/api/v1/time-entries/{$this->timeEntry->id}"
    );
    
    $response->assertOk();
    $this->assertDatabaseMissing('time_entries', ['id' => $this->timeEntry->id]);
});

test('developer cannot delete submitted entry', function () {
    $this->timeEntry->update(['status' => TimeEntry::STATUS_SUBMITTED]);
    
    $response = $this->actingAs($this->developer)->deleteJson(
        "/api/v1/time-entries/{$this->timeEntry->id}"
    );
    
    $response->assertStatus(400);
});

test('admin can view all time entries', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/time-entries?all_users=1');
    
    $response->assertOk();
});

test('manager can view team time entries', function () {
    $response = $this->actingAs($this->manager)->getJson('/api/v1/time-entries?all_users=1');
    
    $response->assertOk();
});

test('developer can view todays entries', function () {
    // Create an entry for today
    TimeEntry::create([
        'user_id' => $this->developer->id,
        'project_id' => $this->project->id,
        'timesheet_id' => $this->timesheet->id,
        'description' => 'Today work',
        'started_at' => Carbon::today()->addHours(9),
        'ended_at' => Carbon::today()->addHours(10),
        'duration_minutes' => 60,
        'is_billable' => true,
        'status' => TimeEntry::STATUS_DRAFT,
    ]);
    
    $response = $this->actingAs($this->developer)->getJson('/api/v1/time-entries-today');
    
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['entries', 'total_minutes']]);
});
