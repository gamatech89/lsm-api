<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteRecoveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Project $project,
        protected string $downtimeDuration,
    ) {}

    /**
     * Use the same channel preferences as the site_down trigger.
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
        return (new MailMessage)
            ->subject("✅ Site Recovered: {$this->project->name}")
            ->greeting("{$this->project->name} is back online!")
            ->line("**URL:** {$this->project->url}")
            ->line("**Recovered at:** " . now()->format('Y-m-d H:i:s'))
            ->line("**Approximate downtime:** {$this->downtimeDuration}")
            ->action('View Project', config('app.frontend_url') . "/projects/{$this->project->id}")
            ->salutation('— Landeseiten Maintenance');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'site_recovered',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'project_url' => $this->project->url,
            'downtime_duration' => $this->downtimeDuration,
            'severity' => 'info',
        ];
    }
}
