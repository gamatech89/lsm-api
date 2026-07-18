<?php

namespace App\Notifications;

use App\Models\Credential;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CredentialAccessGrantedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Credential $credential) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $projectName = $this->credential->project?->name ?? 'your project';
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $projectUrl  = $frontendUrl . '/projects/' . $this->credential->project_id . '?section=credentials';

        return (new MailMessage)
            ->subject("Credential access granted: {$this->credential->title}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("You have been granted access to the following credential on **{$projectName}**:")
            ->line("**{$this->credential->title}** ({$this->credential->type})")
            ->action('View Credentials', $projectUrl)
            ->line('You can now view and copy this credential from the project Credentials tab or Vault.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'credential_id'   => $this->credential->id,
            'credential_title' => $this->credential->title,
            'project_id'      => $this->credential->project_id,
            'project_name'    => $this->credential->project?->name,
            'message'         => "You've been granted access to credential: {$this->credential->title}",
        ];
    }
}
