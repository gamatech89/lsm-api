<?php
// tests/Feature/StaffTicketThreadTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketResolvedNotification;
use App\Notifications\SupportTicketStaffReplyNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function staffTicket(array $overrides = []): SupportTicket
{
    $project = Project::factory()->create();

    return SupportTicket::create(array_merge([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Staff thread test',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'client_name' => 'Client',
        'status' => 'open',
        'priority' => 'high',
    ], $overrides));
}

test('staff reply is stored and the client is emailed', function () {
    Notification::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();

    $response = $this->actingAs($admin)->post("/api/v1/support-tickets/{$ticket->id}/messages", [
        'message' => 'We deployed a fix',
        'attachments' => [UploadedFile::fake()->image('fix.png')],
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    expect($ticket->messages()->count())->toBe(1);
    expect($ticket->messages()->first()->author_type)->toBe('staff');
    expect($ticket->attachments()->count())->toBe(1);

    Notification::assertSentOnDemand(
        SupportTicketStaffReplyNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'client@example.com'
    );
});

test('developer not assigned to the project cannot reply', function () {
    $developer = User::factory()->create(['role' => 'developer']);
    $ticket = staffTicket();

    $this->actingAs($developer)->post("/api/v1/support-tickets/{$ticket->id}/messages", [
        'message' => 'sneaky',
    ], ['Accept' => 'application/json'])->assertForbidden();
});

test('show includes the message thread with attachments', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();
    $ticket->addClientMessage('Client says hi');

    $response = $this->actingAs($admin)->getJson("/api/v1/support-tickets/{$ticket->id}");

    $response->assertOk();
    expect($response->json('data.messages'))->toHaveCount(1);
    expect($response->json('data.messages.0.author_type'))->toBe('client');
});

test('resolving a ticket via update emails the client once', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();

    $this->actingAs($admin)->putJson("/api/v1/support-tickets/{$ticket->id}", [
        'status' => 'resolved',
        'resolution_notes' => 'Fixed the CSS',
    ])->assertOk();

    Notification::assertSentOnDemand(
        SupportTicketResolvedNotification::class,
        fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'client@example.com'
    );

    // updating again while already resolved must not re-send
    $this->actingAs($admin)->putJson("/api/v1/support-tickets/{$ticket->id}", [
        'priority' => 'low',
    ])->assertOk();

    Notification::assertSentOnDemandTimes(SupportTicketResolvedNotification::class, 1);
});

test('staff can download attachments, unauthenticated users cannot', function () {
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin']);
    $ticket = staffTicket();
    $path = UploadedFile::fake()->image('x.png')->store("support-attachments/{$ticket->id}", 'local');
    $attachment = $ticket->attachments()->create([
        'filename' => 'x.png', 'path' => $path, 'mime' => 'image/png', 'size' => 10,
    ]);

    // Unauthenticated check must run before actingAs() below: Laravel's test
    // client resolves the auth guard as a container singleton, so once
    // actingAs() sets a user on it, later requests in the same test inherit
    // that auth even without re-calling actingAs.
    $this->getJson("/api/v1/support-tickets/attachments/{$attachment->id}")->assertStatus(401);
    $this->actingAs($admin)->get("/api/v1/support-tickets/attachments/{$attachment->id}")->assertOk();
});
