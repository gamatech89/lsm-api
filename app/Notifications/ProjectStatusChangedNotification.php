<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Project $project,
        protected string $statusType, // 'health' or 'security'
        protected string $oldStatus,
        protected string $newStatus
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusLabel = $this->statusType === 'health' ? 'Health Status' : 'Security Status';
        $emoji = $this->getStatusEmoji();
        
        return (new MailMessage)
            ->subject("{$emoji} {$statusLabel} Changed: {$this->project->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("The **{$statusLabel}** for project **{$this->project->name}** has been updated:")
            ->line("**Previous Status:** {$this->oldStatus}")
            ->line("**New Status:** {$this->newStatus}")
            ->action('View Project', url("/projects/{$this->project->id}"))
            ->line('Please review the project if necessary.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'status_type' => $this->statusType,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'message' => "{$this->statusType} status changed from {$this->oldStatus} to {$this->newStatus}",
        ];
    }

    private function getStatusEmoji(): string
    {
        return match($this->newStatus) {
            'good', 'healthy' => '✅',
            'warning', 'at_risk' => '⚠️',
            'critical', 'vulnerable' => '🚨',
            default => '📊',
        };
    }
}
