<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\ProjectHardeningPause;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HardeningPauseOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Project $project,
        protected ProjectHardeningPause $pause,
    ) {}

    /**
     * Determine which channels to use based on project notification preferences.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $prefs = $this->project->notification_preferences ?? [];

        if (!empty($prefs['email_alerts_enabled'])) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("⚠️ Hardening pause overdue: {$this->project->name}")
            ->greeting("Archive rule still paused on {$this->project->name}")
            ->line("**URL:** {$this->project->url}")
            ->line("**Pause should have ended:** {$this->pause->paused_until->format('Y-m-d H:i')} UTC")
            ->line('The block_archives rule in wp-content/.htaccess could not be re-enabled automatically. Until it is back on, backup archives (.wpress, .zip, .sql) under wp-content may be publicly downloadable.')
            ->line('Open the Security panel and use "Re-enable now". If the site is unreachable or the plugin is older than 2.10.0, fix that first.')
            ->action('Open Security Panel', config('app.frontend_url') . "/projects/{$this->project->id}?section=security")
            ->salutation('— Landeseiten Maintenance');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hardening_pause_overdue',
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'project_url' => $this->project->url,
            'pause_id' => $this->pause->id,
            'paused_by' => $this->pause->user_id,
            'paused_until' => $this->pause->paused_until->toISOString(),
            'message' => "⚠️ {$this->project->name} — the archive rule pause is overdue, backups may be publicly downloadable",
            'severity' => 'critical',
        ];
    }
}
