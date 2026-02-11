<?php

namespace App\Notifications;

use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

class BackupCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The backup that was completed.
     */
    public Backup $backup;

    /**
     * Create a new notification instance.
     */
    public function __construct(Backup $backup)
    {
        $this->backup = $backup;
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
        $size = $this->backup->size ? $this->formatBytes($this->backup->size) : 'Unknown';

        return (new MailMessage)
            ->subject("✅ Backup Completed: {$project->name}")
            ->greeting("Backup Successful!")
            ->line("A backup has been completed successfully for **{$project->name}**.")
            ->line("**Backup Details:**")
            ->line("- **Type:** " . ucfirst($this->backup->type))
            ->line("- **Size:** {$size}")
            ->line("- **Created:** " . $this->backup->created_at->format('M d, Y H:i'))
            ->when($this->backup->includes_database, fn($mail) => $mail->line("- ✓ Includes database"))
            ->when($this->backup->includes_files, fn($mail) => $mail->line("- ✓ Includes files"))
            ->when($this->backup->includes_uploads, fn($mail) => $mail->line("- ✓ Includes uploads"))
            ->action('View Backup', url("/projects/{$project->id}/backups"))
            ->line('The backup is now available for download.');
    }

    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(object $notifiable): SlackMessage
    {
        $project = $this->backup->project;
        $size = $this->backup->size ? $this->formatBytes($this->backup->size) : 'Unknown';

        return (new SlackMessage)
            ->success()
            ->content("✅ Backup completed for {$project->name}")
            ->attachment(function ($attachment) use ($project, $size) {
                $attachment
                    ->title($project->name, url("/projects/{$project->id}/backups"))
                    ->fields([
                        'Type' => ucfirst($this->backup->type),
                        'Size' => $size,
                        'Created' => $this->backup->created_at->format('M d, Y H:i'),
                    ]);
            });
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'backup_completed',
            'backup_id' => $this->backup->id,
            'project_id' => $this->backup->project_id,
            'project_name' => $this->backup->project->name,
            'backup_type' => $this->backup->type,
            'backup_size' => $this->backup->size,
            'message' => "Backup completed for {$this->backup->project->name}",
        ];
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
