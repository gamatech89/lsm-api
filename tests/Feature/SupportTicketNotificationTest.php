<?php

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReceivedNotification;
use App\Notifications\SupportTicketStaffReplyNotification;
use Illuminate\Support\Facades\Notification;

test('notifiableTeamMembers includes admins and assigned team, deduplicated', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $manager = User::factory()->create(['role' => 'manager']);
    $developer = User::factory()->create(['role' => 'developer']);
    $outsider = User::factory()->create(['role' => 'developer']);
    $flaggedAdmin = User::factory()->create(['role' => 'developer', 'is_admin' => true]);

    $project = Project::factory()->create([
        'manager_id' => $manager->id,
        'developer_id' => $developer->id,
    ]);
    // also attach via many-to-many to prove dedup
    $project->developers()->attach($developer->id);

    $members = $project->notifiableTeamMembers();
    $ids = $members->pluck('id');

    expect($ids)->toContain($admin->id);
    expect($ids)->toContain($manager->id);
    expect($ids)->toContain($developer->id);
    expect($ids)->toContain($flaggedAdmin->id);
    expect($ids)->not->toContain($outsider->id);
    expect($ids->count())->toBe($ids->unique()->count());
});

test('staff notification renders mail and database payload', function () {
    $project = Project::factory()->create();
    $ticket = SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'urgent',
        'subject' => 'Site broken',
        'message' => 'Help!',
        'client_email' => 'client@example.com',
        'client_name' => 'Cl',
        'status' => 'open',
        'priority' => 'critical',
    ]);
    $admin = User::factory()->create(['role' => 'admin']);

    $notification = new SupportTicketReceivedNotification($ticket);

    expect($notification->via($admin))->toBe(['database', 'mail']);
    $mail = $notification->toMail($admin);
    expect($mail->subject)->toContain($ticket->ticket_number);
    $array = $notification->toArray($admin);
    expect($array['type'])->toBe('support_ticket_received');
    expect($array['ticket_id'])->toBe($ticket->id);
});

test('staff reply notification goes to the client email on demand', function () {
    Notification::fake();

    $project = Project::factory()->create();
    $ticket = SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Broken',
        'message' => 'desc',
        'client_email' => 'client@example.com',
        'status' => 'open',
        'priority' => 'high',
    ]);
    $staff = User::factory()->create(['role' => 'admin']);
    $message = $ticket->addStaffMessage($staff, 'We fixed it');

    Notification::route('mail', $ticket->client_email)
        ->notify(new SupportTicketStaffReplyNotification($ticket, $message));

    Notification::assertSentOnDemand(
        SupportTicketStaffReplyNotification::class,
        fn ($notification, $channels, $notifiable) =>
            $notifiable->routes['mail'] === 'client@example.com' && $channels === ['mail']
    );
});
