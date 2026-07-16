<?php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;

function threadTicket(array $overrides = []): SupportTicket
{
    $project = Project::factory()->create();

    return SupportTicket::create(array_merge([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Thread test',
        'message' => 'Original description',
        'client_email' => 'client@example.com',
        'client_name' => 'Client Name',
        'status' => 'open',
        'priority' => 'high',
    ], $overrides));
}

test('a client message is appended to the thread with author info', function () {
    $ticket = threadTicket();

    $msg = $ticket->addClientMessage('More details here');

    expect($msg->author_type)->toBe('client');
    expect($msg->author_name)->toBe('Client Name');
    expect($msg->user_id)->toBeNull();
    expect($ticket->messages()->count())->toBe(1);
});

test('a staff message records the authoring user', function () {
    $ticket = threadTicket();
    $staff = User::factory()->create(['role' => 'admin', 'name' => 'Agent Smith']);

    $msg = $ticket->addStaffMessage($staff, 'We are on it');

    expect($msg->author_type)->toBe('staff');
    expect($msg->user_id)->toBe($staff->id);
    expect($msg->author_name)->toBe('Agent Smith');
});

test('a client reply reopens a resolved ticket and marks it unread', function () {
    $ticket = threadTicket(['status' => 'resolved', 'read_at' => now(), 'resolved_at' => now()]);

    $ticket->addClientMessage('It is still broken');

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->read_at)->toBeNull();
    expect($ticket->resolved_at)->toBeNull();
});

test('a client reply on an open ticket clears read_at but keeps status', function () {
    $ticket = threadTicket(['status' => 'in_progress', 'read_at' => now()]);

    $ticket->addClientMessage('Bump');

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->read_at)->toBeNull();
});

test('a staff reply does not reopen or unread the ticket', function () {
    $ticket = threadTicket(['status' => 'resolved', 'read_at' => now()]);
    $staff = User::factory()->create(['role' => 'admin']);

    $ticket->addStaffMessage($staff, 'Fixed for real');

    $ticket->refresh();
    expect($ticket->status)->toBe('resolved');
    expect($ticket->read_at)->not->toBeNull();
});

test('deleting a ticket cascades to messages and attachments', function () {
    $ticket = threadTicket();
    $msg = $ticket->addClientMessage('With attachment');
    $ticket->attachments()->create([
        'support_ticket_message_id' => $msg->id,
        'filename' => 'shot.png',
        'path' => 'support-attachments/1/shot.png',
        'mime' => 'image/png',
        'size' => 1234,
    ]);

    $ticket->forceDelete();

    expect(\App\Models\SupportTicketMessage::count())->toBe(0);
    expect(\App\Models\SupportTicketAttachment::count())->toBe(0);
});
