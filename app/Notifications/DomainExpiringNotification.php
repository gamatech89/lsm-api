<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DomainExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $domainExpiring = $triggers['domain_expiring'] ?? [];

        if (!empty($domainExpiring['email']) && !empty($prefs['email_alerts_enabled'])) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $urgency = $this->daysRemaining <= 7 ? '🚨' : '⚠️';

        return (new MailMessage)
            ->subject("{$urgency} Domain Expiring: {$this->project->name} ({$this->daysRemaining} days)")
            ->greeting("Domain Registration Alert for {$this->project->name}")
            ->line("**URL:** {$this->project->url}")
            ->line("**Registrar:** " . ($this->project->domain_registrar ?? 'Unknown'))
            ->line("**Domain Expires:** {$this->expiresAt}")
            ->line("**Days Remaining:** {$this->daysRemaining}")
            ->line($this->daysRemaining <= 7
                ? '⚠️ **Urgent:** the domain registration expires in less than a week! Renew immediately — an expired domain takes the site offline.'
                : 'Please renew the domain registration before it expires.')
            ->action('View Project', config('app.frontend_url') . "/projects/{$this->project->id}")
            ->salutation('— LSM Platform');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'domain_expiring',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'project_url' => $this->project->url,
            'expires_at' => $this->expiresAt,
            'days_remaining' => $this->daysRemaining,
            'severity' => $this->daysRemaining <= 7 ? 'critical' : 'warning',
        ];
    }
}
