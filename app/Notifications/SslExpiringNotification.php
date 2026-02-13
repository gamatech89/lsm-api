<?php

namespace App\Notifications;

use App\Models\Project;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SslExpiringNotification extends Notification
{

    public function __construct(
        protected Project $project,
        protected string $expiresAt,
        protected int $daysRemaining,
    ) {}

    /**
     * Determine which channels to use based on project notification preferences.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $prefs = $this->project->notification_preferences ?? [];
        $triggers = $prefs['triggers'] ?? [];
        $sslExpiring = $triggers['ssl_expiring'] ?? [];

        if (!empty($sslExpiring['email']) && !empty($prefs['email_alerts_enabled'])) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $urgency = $this->daysRemaining <= 7 ? '🚨' : '⚠️';

        return (new MailMessage)
            ->subject("{$urgency} SSL Expiring: {$this->project->name} ({$this->daysRemaining} days)")
            ->greeting("SSL Certificate Alert for {$this->project->name}")
            ->line("**URL:** {$this->project->url}")
            ->line("**SSL Expires:** {$this->expiresAt}")
            ->line("**Days Remaining:** {$this->daysRemaining}")
            ->line($this->daysRemaining <= 7
                ? '⚠️ **Urgent:** SSL certificate expires in less than a week! Renew immediately to avoid downtime.'
                : 'Please renew the SSL certificate before it expires to avoid security warnings.')
            ->action('View Project', url("/projects/{$this->project->id}"))
            ->salutation('— LSM Platform');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ssl_expiring',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'project_url' => $this->project->url,
            'expires_at' => $this->expiresAt,
            'days_remaining' => $this->daysRemaining,
            'severity' => $this->daysRemaining <= 7 ? 'critical' : 'warning',
        ];
    }
}
