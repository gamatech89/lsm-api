<?php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;

function makeTicket(Project $project, string $subject): SupportTicket
{
    return SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => $subject,
        'message' => 'Something is broken',
        'client_email' => 'client@example.com',
        'status' => 'open',
        'priority' => 'high',
    ]);
}

test('developer only sees tickets from assigned projects in the global list', function () {
    $developer = User::factory()->create(['role' => 'developer']);
    $assigned = Project::factory()->create(['developer_id' => $developer->id]);
    $other = Project::factory()->create();

    makeTicket($assigned, 'Mine');
    makeTicket($other, 'NotMine');

    $response = $this->actingAs($developer)->getJson('/api/v1/support-tickets');

    $response->assertOk();
    $subjects = collect($response->json('data'))->pluck('subject');

    expect($subjects)->toContain('Mine');
    expect($subjects)->not->toContain('NotMine');
});

test('manager only sees tickets from managed projects in the global list', function () {
    $manager = User::factory()->create(['role' => 'manager']);
    $managed = Project::factory()->create(['manager_id' => $manager->id]);
    $other = Project::factory()->create();

    makeTicket($managed, 'Managed');
    makeTicket($other, 'Foreign');

    $response = $this->actingAs($manager)->getJson('/api/v1/support-tickets');

    $response->assertOk();
    $subjects = collect($response->json('data'))->pluck('subject');

    expect($subjects)->toContain('Managed');
    expect($subjects)->not->toContain('Foreign');
});

test('admin sees all tickets across every project in the global list', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $a = Project::factory()->create();
    $b = Project::factory()->create();

    makeTicket($a, 'FromA');
    makeTicket($b, 'FromB');

    $response = $this->actingAs($admin)->getJson('/api/v1/support-tickets');

    $response->assertOk();
    $subjects = collect($response->json('data'))->pluck('subject');

    expect($subjects)->toContain('FromA');
    expect($subjects)->toContain('FromB');
});
