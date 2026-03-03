<?php

namespace App\Notifications;

use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

class BackupFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The backup that failed.
     */
    public Backup $backup;

    /**
     * The error message.
     */
    public string $error;

    /**
     * Create a new notification instance.
     */
    public function __construct(Backup $backup, string $error)
    {
        $this->backup = $backup;
        $this->error = $error;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if (config('backup.notifications.slack.enabled')) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->backup->project;

        return (new MailMessage)
            ->subject("❌ Backup Failed: {$project->name}")
            ->error()
            ->greeting("Backup Failed!")
            ->line("A backup has failed for **{$project->name}**.")
            ->line("**Error Details:**")
            ->line($this->error)
            ->line("**Backup Info:**")
            ->line("- **Type:** " . ucfirst($this->backup->type))
            ->line("- **Attempted:** " . $this->backup->started_at?->format('M d, Y H:i'))
            ->action('View Project', config('app.frontend_url') . "/projects/{$project->id}")
            ->line('Please investigate and retry the backup if necessary.');
    }

    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(object $notifiable): SlackMessage
    {
        $project = $this->backup->project;

        return (new SlackMessage)
            ->error()
            ->content("❌ Backup failed for {$project->name}")
            ->attachment(function ($attachment) use ($project) {
                $attachment
                    ->title($project->name, config('app.frontend_url') . "/projects/{$project->id}")
                    ->fields([
                        'Type' => ucfirst($this->backup->type),
                        'Error' => substr($this->error, 0, 200),
                        'Attempted' => $this->backup->started_at?->format('M d, Y H:i') ?? 'Unknown',
                    ])
                    ->color('danger');
            });
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'backup_failed',
            'backup_id' => $this->backup->id,
            'project_id' => $this->backup->project_id,
            'project_name' => $this->backup->project->name,
            'backup_type' => $this->backup->type,
            'error' => $this->error,
            'message' => "Backup failed for {$this->backup->project->name}: {$this->error}",
        ];
    }
}
