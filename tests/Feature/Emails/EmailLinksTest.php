<?php
// tests/Feature/Emails/EmailLinksTest.php
use App\Models\Backup;
use App\Models\Credential;
use App\Models\Project;
use App\Models\SecurityScan;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\BackupCompletedNotification;
use App\Notifications\BackupFailedNotification;
use App\Notifications\CredentialAccessGrantedNotification;
use App\Notifications\MalwareDetectedNotification;
use App\Notifications\SupportTicketClientReplyNotification;
use App\Notifications\SupportTicketReceivedNotification;
use App\Notifications\TodoAddedNotification;
use App\Notifications\TodoAssignedNotification;
use App\Notifications\TodoDueDateReminderNotification;

beforeEach(function () {
    // Simulate a production-like frontend URL so the "no bare localhost" assertions
    // are meaningful (in the test env, app.frontend_url otherwise defaults to localhost:3000).
    config(['app.frontend_url' => 'https://app.lsmplatform.com']);
});

function actionUrl($notification): ?string
{
    $user = User::factory()->make(['name' => 'T', 'email' => 't@example.com']);
    return $notification->toMail($user)->actionUrl;
}

it('links malware email to the security section', function () {
    $project = Project::factory()->make(['id' => 7, 'name' => 'Acme']);
    $scan = SecurityScan::factory()->make(['project_id' => 7, 'risk_level' => 'high', 'threats_found' => 2]);
    expect(actionUrl(new MalwareDetectedNotification($project, $scan)))
        ->toContain('/projects/7?section=security')
        ->and(actionUrl(new MalwareDetectedNotification($project, $scan)))
        ->not->toContain('/security"')  // no bare /security path
        ->and(actionUrl(new MalwareDetectedNotification($project, $scan)))
        ->not->toContain('localhost');
});

it('links backup-completed email to the backups section', function () {
    $project = Project::factory()->create(['id' => 8, 'name' => 'Beta']);
    $backup = Backup::create([
        'project_id' => $project->id,
        'type' => 'manual',
        'status' => 'completed',
        'includes_database' => true,
        'includes_files' => true,
        'includes_uploads' => true,
        'file_size' => 1024,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    expect(actionUrl(new BackupCompletedNotification($backup)))
        ->toContain('/projects/8?section=backups')
        ->and(actionUrl(new BackupCompletedNotification($backup)))
        ->not->toContain('/projects/8/backups');
});

it('links backup-failed email to the backups section', function () {
    $project = Project::factory()->create(['id' => 9, 'name' => 'Gamma']);
    $backup = Backup::create([
        'project_id' => $project->id,
        'type' => 'manual',
        'status' => 'failed',
        'started_at' => now()->subMinutes(5),
        'error_message' => 'Disk full',
    ]);

    expect(actionUrl(new BackupFailedNotification($backup, 'Disk full')))
        ->toContain('/projects/9?section=backups');
});

it('links support-ticket-received email to the support section', function () {
    $project = Project::factory()->create(['id' => 10, 'name' => 'Delta']);
    $ticket = SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Broken form',
        'message' => 'The contact form is broken.',
        'client_email' => 'client@example.com',
        'client_name' => 'Client',
        'status' => 'open',
        'priority' => 'high',
    ]);

    expect(actionUrl(new SupportTicketReceivedNotification($ticket)))
        ->toContain('/projects/10?section=support');
});

it('links support-ticket-client-reply email to the support section', function () {
    $project = Project::factory()->create(['id' => 11, 'name' => 'Epsilon']);
    $ticket = SupportTicket::create([
        'project_id' => $project->id,
        'type' => 'bug',
        'subject' => 'Broken form',
        'message' => 'The contact form is broken.',
        'client_email' => 'client@example.com',
        'client_name' => 'Client',
        'status' => 'open',
        'priority' => 'high',
    ]);
    $message = SupportTicketMessage::create([
        'support_ticket_id' => $ticket->id,
        'author_type' => 'client',
        'author_name' => 'Client',
        'message' => 'Any update?',
    ]);

    expect(actionUrl(new SupportTicketClientReplyNotification($ticket, $message)))
        ->toContain('/projects/11?section=support');
});

it('links todo-added email to the todos section', function () {
    $project = Project::factory()->create(['id' => 12, 'name' => 'Zeta']);
    $todo = Todo::factory()->create(['project_id' => $project->id, 'title' => 'Fix header']);

    expect(actionUrl(new TodoAddedNotification($todo, 'Alice')))
        ->toContain('/projects/12?section=todos');
});

it('links todo-assigned email to the todos section', function () {
    $project = Project::factory()->create(['id' => 13, 'name' => 'Eta']);
    $todo = Todo::factory()->create(['project_id' => $project->id, 'title' => 'Fix footer']);

    expect(actionUrl(new TodoAssignedNotification($todo)))
        ->toContain('/projects/13?section=todos');
});

it('links todo-due-date-reminder email to the todos section', function () {
    $project = Project::factory()->create(['id' => 14, 'name' => 'Theta']);
    $todo = Todo::factory()->create([
        'project_id' => $project->id,
        'title' => 'Renew SSL',
        'due_date' => now()->addDay(),
    ]);

    expect(actionUrl(new TodoDueDateReminderNotification($todo)))
        ->toContain('/projects/14?section=todos');
});

it('links credential-access-granted email to the credentials section', function () {
    $project = Project::factory()->create(['id' => 15, 'name' => 'Iota']);
    $credential = Credential::factory()->create(['project_id' => $project->id, 'title' => 'DB Admin']);

    expect(actionUrl(new CredentialAccessGrantedNotification($credential)))
        ->toContain('/projects/15?section=credentials');
});
