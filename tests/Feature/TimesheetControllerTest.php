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
    
    // Add a time entry to the timesheet
    TimeEntry::create([
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

test('developer can view their timesheets', function () {
    $response = $this->actingAs($this->developer)->getJson('/api/v1/timesheets');
    
    $response->assertOk();
});

test('developer can view current week timesheet', function () {
    $response = $this->actingAs($this->developer)->getJson('/api/v1/timesheets/current');
    
    $response->assertOk();
    $response->assertJsonStructure(['data']);
});

test('developer can submit their timesheet', function () {
    $response = $this->actingAs($this->developer)->postJson(
        "/api/v1/timesheets/{$this->timesheet->id}/submit"
    );
    
    $response->assertOk();
    $this->timesheet->refresh();
    expect($this->timesheet->status)->toBe(Timesheet::STATUS_SUBMITTED);
});

test('developer cannot submit empty timesheet', function () {
    // Create empty timesheet
    $emptyTimesheet = Timesheet::getOrCreateForWeek($this->developer->id, Carbon::now()->subWeeks(2));
    
    $response = $this->actingAs($this->developer)->postJson(
        "/api/v1/timesheets/{$emptyTimesheet->id}/submit"
    );
    
    $response->assertStatus(400);
});

test('developer cannot submit another users timesheet', function () {
    $response = $this->actingAs($this->otherDeveloper)->postJson(
        "/api/v1/timesheets/{$this->timesheet->id}/submit"
    );
    
    $response->assertForbidden();
});

test('manager can view pending timesheets', function () {
    // Submit the timesheet first
    $this->timesheet->submit();
    
    $response = $this->actingAs($this->manager)->getJson('/api/v1/timesheets/pending');
    
    $response->assertOk();
});

test('manager can approve submitted timesheet', function () {
    // Submit the timesheet first
    $this->timesheet->submit();
    
    $response = $this->actingAs($this->manager)->postJson(
        "/api/v1/timesheets/{$this->timesheet->id}/approve"
    );
    
    $response->assertOk();
    $this->timesheet->refresh();
    expect($this->timesheet->status)->toBe(Timesheet::STATUS_APPROVED);
});

test('manager can reject submitted timesheet', function () {
    // Submit the timesheet first
    $this->timesheet->submit();
    
    $response = $this->actingAs($this->manager)->postJson(
        "/api/v1/timesheets/{$this->timesheet->id}/reject",
        ['reason' => 'Missing project details']
    );
    
    $response->assertOk();
    $this->timesheet->refresh();
    expect($this->timesheet->status)->toBe(Timesheet::STATUS_REJECTED);
});

test('developer cannot approve timesheet', function () {
    $this->timesheet->submit();
    
    $response = $this->actingAs($this->developer)->postJson(
        "/api/v1/timesheets/{$this->timesheet->id}/approve"
    );
    
    $response->assertForbidden();
});

test('developer can view timesheet by week', function () {
    $week = Carbon::now()->isoWeek();
    $year = Carbon::now()->isoWeekYear();
    
    $response = $this->actingAs($this->developer)->getJson(
        "/api/v1/timesheets/by-week?week={$week}&year={$year}"
    );
    
    $response->assertOk();
});

test('admin can view any timesheet', function () {
    $response = $this->actingAs($this->admin)->getJson(
        "/api/v1/timesheets/{$this->timesheet->id}"
    );
    
    $response->assertOk();
});
