<?php
// tests/Feature/PluginTicketEndpointsTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketClientReplyNotification;
use App\Notifications\SupportTicketReceivedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function pluginProject(string $key = 'PLUGIN_KEY_1'): Project
{
    return Project::factory()->create(['health_check_secret' => $key]);
}

function pluginTicket(Project $project, array $overrides = []): SupportTicket
{
    return SupportTicket::create(array_merge([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Plugin ticket',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'client_name' => 'Client',
        'status' => 'open',
        'priority' => 'high',
    ], $overrides));
}

test('lists only the authenticated project tickets', function () {
    $mine = pluginProject('KEY_MINE');
    $other = pluginProject('KEY_OTHER');
    pluginTicket($mine, ['subject' => 'Mine']);
    pluginTicket($other, ['subject' => 'NotMine']);

    $response = $this->getJson('/api/v1/plugin/support-tickets', ['X-LSM-Key' => 'KEY_MINE']);

    $response->assertOk();
    $subjects = collect($response->json('data'))->pluck('subject');
    expect($subjects)->toContain('Mine');
    expect($subjects)->not->toContain('NotMine');
});

test('creates a ticket with attachments and notifies staff', function () {
    Storage::fake('local');
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = pluginProject('KEY_CREATE');

    $response = $this->post('/api/v1/plugin/support-tickets', [
        'type' => 'urgent',
        'subject' => 'Homepage down',
        'message' => 'The homepage shows an error',
        'client_email' => 'owner@site.com',
        'client_name' => 'Owner',
        'problem_page' => 'https://site.com/',
        'attachments' => [UploadedFile::fake()->image('screenshot.png')],
    ], ['X-LSM-Key' => 'KEY_CREATE', 'Accept' => 'application/json']);

    $response->assertCreated();
    $ticket = SupportTicket::where('subject', 'Homepage down')->firstOrFail();
    expect($ticket->priority)->toBe('critical'); // urgent → critical
    expect($ticket->attachments()->count())->toBe(1);
    Notification::assertSentTo($admin, SupportTicketReceivedNotification::class);
});

test('shows a ticket with its full thread, 404s for foreign tickets', function () {
    $project = pluginProject('KEY_SHOW');
    $ticket = pluginTicket($project);
    $ticket->addClientMessage('First reply');
    $staff = User::factory()->create(['role' => 'admin']);
    $ticket->addStaffMessage($staff, 'Staff answer');

    $response = $this->getJson("/api/v1/plugin/support-tickets/{$ticket->id}", ['X-LSM-Key' => 'KEY_SHOW']);
    $response->assertOk();
    expect($response->json('data.messages'))->toHaveCount(2);
    expect($response->json('data.messages.1.author_type'))->toBe('staff');

    $foreign = pluginProject('KEY_FOREIGN');
    $foreignTicket = pluginTicket($foreign);
    $this->getJson("/api/v1/plugin/support-tickets/{$foreignTicket->id}", ['X-LSM-Key' => 'KEY_SHOW'])
        ->assertNotFound();
});

test('client reply reopens the ticket and notifies staff', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = pluginProject('KEY_REPLY');
    $ticket = pluginTicket($project, ['status' => 'resolved', 'read_at' => now(), 'resolved_at' => now()]);

    $response = $this->post("/api/v1/plugin/support-tickets/{$ticket->id}/messages", [
        'message' => 'Still broken!',
    ], ['X-LSM-Key' => 'KEY_REPLY', 'Accept' => 'application/json']);

    $response->assertCreated();
    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->read_at)->toBeNull();
    Notification::assertSentTo($admin, SupportTicketClientReplyNotification::class);
});

test('downloads an attachment belonging to the project, 404s otherwise', function () {
    Storage::fake('local');
    $project = pluginProject('KEY_DL');
    $ticket = pluginTicket($project);
    $path = UploadedFile::fake()->image('mine.png')->store("support-attachments/{$ticket->id}", 'local');
    $attachment = $ticket->attachments()->create([
        'filename' => 'mine.png', 'path' => $path, 'mime' => 'image/png', 'size' => 100,
    ]);

    $this->get("/api/v1/plugin/support-tickets/attachments/{$attachment->id}", ['X-LSM-Key' => 'KEY_DL'])
        ->assertOk();

    $this->get("/api/v1/plugin/support-tickets/attachments/{$attachment->id}", ['X-LSM-Key' => 'KEY_DL_NOPE'])
        ->assertStatus(401);

    pluginProject('KEY_DL2');
    $this->get("/api/v1/plugin/support-tickets/attachments/{$attachment->id}", ['X-LSM-Key' => 'KEY_DL2'])
        ->assertNotFound();
});

test('legacy webhook still creates tickets and now notifies staff', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $project = Project::factory()->create([
        'url' => 'https://legacyhook.example.com',
        'health_check_secret' => 'LEGACY_KEY',
    ]);

    $response = $this->postJson('/api/v1/webhooks/support-ticket', [
        'api_key' => 'LEGACY_KEY',
        'site_url' => 'https://legacyhook.example.com',
        'type' => 'question',
        'subject' => 'Legacy path',
        'message' => 'Old plugin version',
        'client_email' => 'old@client.com',
    ]);

    $response->assertCreated();
    Notification::assertSentTo($admin, SupportTicketReceivedNotification::class);
});

test('validation errors return JSON 422 even without an Accept header', function () {
    pluginProject('KEY_NOACCEPT');

    $response = $this->post('/api/v1/plugin/support-tickets', [
        'type' => 'bug',
        // subject/message/client_email missing
    ], ['X-LSM-Key' => 'KEY_NOACCEPT']);

    $response->assertStatus(422);
    expect($response->json('errors'))->not->toBeNull();
});
