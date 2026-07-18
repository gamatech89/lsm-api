<?php
// tests/Feature/Emails/AllNotificationsRenderTest.php
//
// Render-verifies EVERY mail-channel notification in app/Notifications/: each one
// must render to branded HTML (logo + brand name present) with no localhost links
// and none of the two old dead paths (bare /projects/{id}/security or
// /projects/{id}/backups without a ?section= query string).

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
use App\Notifications\DomainExpiringNotification;
use App\Notifications\MalwareDetectedNotification;
use App\Notifications\ProjectAssignedNotification;
use App\Notifications\ProjectStatusChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SiteDownNotification;
use App\Notifications\SiteRecoveredNotification;
use App\Notifications\SslExpiringNotification;
use App\Notifications\SupportTicketClientReplyNotification;
use App\Notifications\SupportTicketReceivedNotification;
use App\Notifications\SupportTicketResolvedNotification;
use App\Notifications\SupportTicketStaffReplyNotification;
use App\Notifications\TodoAddedNotification;
use App\Notifications\TodoAssignedNotification;
use App\Notifications\TodoDueDateReminderNotification;
use App\Notifications\TwoFactorCodeNotification;
use App\Notifications\WelcomeNotification;

beforeEach(function () {
    // Simulate production-like URLs so the "not localhost" assertions are meaningful
    // (test env otherwise defaults both to localhost). The mail theme's logo <img> src
    // is built from app.url (App URL, used for the /images/email-logo.png asset host)
    // while notification action links are built from app.frontend_url (dashboard).
    config([
        'app.url' => 'https://wartung-ls.com',
        'app.frontend_url' => 'https://wartung-ls.com',
    ]);
});

/**
 * Notification classes deliberately NOT in the dataset below, and why.
 * (Currently empty — every notification in app/Notifications/ has a 'mail' channel
 * and a toMail() that returns a MailMessage, so all 20 are covered.)
 *
 * If a future notification is database-only (its via() never includes 'mail', or it
 * has no toMail()), add its short class name here with a one-line reason so the
 * coverage check below stays accurate instead of silently going stale.
 */
function excludedNotifications(): array
{
    return [];
}

/**
 * One buildable instance per mail-capable notification, keyed by short class name.
 * Keep in sync with app/Notifications/*.php.
 */
function notificationDataset(): array
{
    return [
        'BackupCompletedNotification' => function () {
            $project = Project::factory()->create(['id' => 9101, 'name' => 'Acme Co']);
            $backup = Backup::create([
                'project_id' => $project->id,
                'type' => 'manual',
                'status' => 'completed',
                'includes_database' => true,
                'includes_files' => true,
                'includes_uploads' => true,
                'file_size' => 2048,
                'started_at' => now()->subMinutes(5),
                'completed_at' => now(),
            ]);

            return new BackupCompletedNotification($backup);
        },

        'BackupFailedNotification' => function () {
            $project = Project::factory()->create(['id' => 9102, 'name' => 'Beta Inc']);
            $backup = Backup::create([
                'project_id' => $project->id,
                'type' => 'manual',
                'status' => 'failed',
                'started_at' => now()->subMinutes(5),
                'error_message' => 'Disk full',
            ]);

            return new BackupFailedNotification($backup, 'Disk full');
        },

        'CredentialAccessGrantedNotification' => function () {
            $project = Project::factory()->create(['id' => 9103, 'name' => 'Gamma LLC']);
            $credential = Credential::factory()->create(['project_id' => $project->id, 'title' => 'DB Admin']);

            return new CredentialAccessGrantedNotification($credential);
        },

        'DomainExpiringNotification' => function () {
            $project = Project::factory()->make([
                'id' => 9104,
                'name' => 'Delta Corp',
                'url' => 'https://delta.example.com',
            ]);

            return new DomainExpiringNotification($project, '2026-08-01', 5);
        },

        'MalwareDetectedNotification' => function () {
            $project = Project::factory()->make(['id' => 9105, 'name' => 'Epsilon']);
            $scan = SecurityScan::factory()->make([
                'project_id' => 9105,
                'risk_level' => 'critical',
                'threats_found' => 3,
            ]);

            return new MalwareDetectedNotification($project, $scan);
        },

        'ProjectAssignedNotification' => function () {
            $project = Project::factory()->make(['id' => 9106, 'name' => 'Zeta']);

            return new ProjectAssignedNotification($project, 'manager');
        },

        'ProjectStatusChangedNotification' => function () {
            $project = Project::factory()->make(['id' => 9107, 'name' => 'Eta']);

            return new ProjectStatusChangedNotification($project, 'health', 'online', 'down_error');
        },

        'ResetPasswordNotification' => function () {
            return new ResetPasswordNotification('reset-token-123');
        },

        'SiteDownNotification' => function () {
            $project = Project::factory()->make(['id' => 9108, 'name' => 'Theta']);

            return new SiteDownNotification($project, 'connection', 'Connection refused', 503);
        },

        'SiteRecoveredNotification' => function () {
            $project = Project::factory()->make(['id' => 9109, 'name' => 'Iota']);

            return new SiteRecoveredNotification($project, '12 minutes');
        },

        'SslExpiringNotification' => function () {
            $project = Project::factory()->make(['id' => 9110, 'name' => 'Kappa']);

            return new SslExpiringNotification($project, '2026-08-10', 10);
        },

        'SupportTicketClientReplyNotification' => function () {
            $project = Project::factory()->create(['id' => 9111, 'name' => 'Lambda']);
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
                'message' => 'Any update on this?',
            ]);

            return new SupportTicketClientReplyNotification($ticket, $message);
        },

        'SupportTicketReceivedNotification' => function () {
            $project = Project::factory()->create(['id' => 9112, 'name' => 'Mu']);
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

            return new SupportTicketReceivedNotification($ticket);
        },

        'SupportTicketResolvedNotification' => function () {
            $project = Project::factory()->create(['id' => 9113, 'name' => 'Nu']);
            $ticket = SupportTicket::create([
                'project_id' => $project->id,
                'type' => 'bug',
                'subject' => 'Broken form',
                'message' => 'The contact form is broken.',
                'client_email' => 'client@example.com',
                'client_name' => 'Client',
                'status' => 'resolved',
                'priority' => 'high',
                'resolution_notes' => 'Fixed the form validation bug.',
            ]);

            return new SupportTicketResolvedNotification($ticket);
        },

        'SupportTicketStaffReplyNotification' => function () {
            $project = Project::factory()->create(['id' => 9114, 'name' => 'Xi']);
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
                'author_type' => 'staff',
                'author_name' => 'Support Agent',
                'message' => 'We are looking into this.',
            ]);

            return new SupportTicketStaffReplyNotification($ticket, $message);
        },

        'TodoAddedNotification' => function () {
            $project = Project::factory()->create(['id' => 9115, 'name' => 'Omicron']);
            $todo = Todo::factory()->create(['project_id' => $project->id, 'title' => 'Fix header']);

            return new TodoAddedNotification($todo, 'Alice');
        },

        'TodoAssignedNotification' => function () {
            $project = Project::factory()->create(['id' => 9116, 'name' => 'Pi']);
            $todo = Todo::factory()->create(['project_id' => $project->id, 'title' => 'Fix footer']);

            return new TodoAssignedNotification($todo);
        },

        'TodoDueDateReminderNotification' => function () {
            $project = Project::factory()->create(['id' => 9117, 'name' => 'Rho']);
            $todo = Todo::factory()->create([
                'project_id' => $project->id,
                'title' => 'Renew SSL',
                'due_date' => now()->addDay(),
            ]);

            return new TodoDueDateReminderNotification($todo);
        },

        'TwoFactorCodeNotification' => function () {
            return new TwoFactorCodeNotification('123456');
        },

        'WelcomeNotification' => function () {
            return new WelcomeNotification('welcome-token-123', 'TempPass123!');
        },
    ];
}

it('keeps the notification dataset in sync with app/Notifications', function () {
    $expected = collect(glob(app_path('Notifications/*.php')))
        ->map(fn (string $path) => basename($path, '.php'))
        ->diff(excludedNotifications())
        ->sort()
        ->values();

    $covered = collect(array_keys(notificationDataset()))
        ->sort()
        ->values();

    expect($covered)->toEqual($expected);
});

it('renders every mail notification branded with no localhost or dead-path links', function (Closure $build) {
    $user = User::factory()->make(['name' => 'Test User', 'email' => 'test@example.com']);

    $notification = $build();
    $mail = $notification->toMail($user);
    $html = (string) $mail->render();

    expect($html)->toContain('/images/email-logo.png')
        ->and($html)->toContain('Landeseiten Maintenance')
        ->and($html)->not->toContain('localhost')
        ->and($html)->not->toContain('/security"')
        ->and($html)->not->toContain('/backups"');
})->with(fn () => notificationDataset());
