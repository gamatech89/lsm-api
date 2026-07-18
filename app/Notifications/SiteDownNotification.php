<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteDownNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Project $project,
        protected string $errorType,
        protected string $errorMessage,
        protected ?int $httpStatus = null,
    ) {}

    /**
     * Determine which channels to use based on project notification preferences.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $prefs = $this->project->notification_preferences ?? [];
        $triggers = $prefs['triggers'] ?? [];
        $siteDown = $triggers['site_down'] ?? [];

        if (!empty($siteDown['email']) && !empty($prefs['email_alerts_enabled'])) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusText = $this->httpStatus ? "HTTP {$this->httpStatus}" : 'Connection Error';

        return (new MailMessage)
            ->subject("🚨 Site Down: {$this->project->name}")
            ->greeting("Alert: {$this->project->name} is down!")
            ->line("**URL:** {$this->project->url}")
            ->line("**Status:** {$statusText}")
            ->line("**Error:** {$this->errorMessage}")
            ->line("**Detected at:** " . now()->format('Y-m-d H:i:s'))
            ->action('View Project', config('app.frontend_url') . "/projects/{$this->project->id}")
            ->salutation('— Landeseiten Maintenance');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'site_down',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'project_url' => $this->project->url,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
            'http_status' => $this->httpStatus,
            'severity' => 'critical',
        ];
    }
}
