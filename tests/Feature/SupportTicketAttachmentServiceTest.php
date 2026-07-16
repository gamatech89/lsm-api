<?php
// tests/Feature/SupportTicketAttachmentServiceTest.php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Services\SupportTicketAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function attachmentTicket(): SupportTicket
{
    $project = Project::factory()->create();

    return SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Attachment test',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'status' => 'open',
        'priority' => 'high',
    ]);
}

test('stores uploaded files under the ticket directory and records metadata', function () {
    Storage::fake('local');
    $ticket = attachmentTicket();
    $service = new SupportTicketAttachmentService();

    $file = UploadedFile::fake()->image('screenshot.png', 800, 600);
    [$attachment] = $service->store($ticket, null, [$file]);

    expect($attachment->filename)->toBe('screenshot.png');
    expect($attachment->support_ticket_id)->toBe($ticket->id);
    expect($attachment->support_ticket_message_id)->toBeNull();
    expect($attachment->path)->toStartWith("support-attachments/{$ticket->id}/");
    Storage::disk('local')->assertExists($attachment->path);
});

test('links attachments to a message when one is given', function () {
    Storage::fake('local');
    $ticket = attachmentTicket();
    $msg = $ticket->addClientMessage('with file');
    $service = new SupportTicketAttachmentService();

    [$attachment] = $service->store($ticket, $msg, [UploadedFile::fake()->image('a.png')]);

    expect($attachment->support_ticket_message_id)->toBe($msg->id);
});

test('validation rules reject more than 5 files, oversized files, and wrong types', function () {
    $rules = SupportTicketAttachmentService::rules();

    $tooMany = validator(
        ['attachments' => array_fill(0, 6, UploadedFile::fake()->image('x.png'))],
        $rules
    );
    expect($tooMany->fails())->toBeTrue();

    $tooBig = validator(
        ['attachments' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]],
        $rules
    );
    expect($tooBig->fails())->toBeTrue();

    $wrongType = validator(
        ['attachments' => [UploadedFile::fake()->create('evil.php', 10, 'text/x-php')]],
        $rules
    );
    expect($wrongType->fails())->toBeTrue();

    $ok = validator(
        ['attachments' => [UploadedFile::fake()->image('ok.jpg'), UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]],
        $rules
    );
    expect($ok->fails())->toBeFalse();
});
