<?php

namespace App\Notifications;

use App\Models\Project;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectAssignedNotification extends Notification
{

    public function __construct(
        protected Project $project,
        protected string $role // 'manager' or 'developer'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $roleLabel = $this->role === 'manager' ? 'Manager' : 'Developer';
        
        return (new MailMessage)
            ->subject("You've been assigned as {$roleLabel} to: {$this->project->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("You have been assigned as the **{$roleLabel}** for the following project:")
            ->line("**Project:** {$this->project->name}")
            ->line("**URL:** " . ($this->project->url ?: 'Not specified'))
            ->action('View Project', url("/projects/{$this->project->id}"))
            ->line('Thank you for being part of the team!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'role' => $this->role,
            'message' => "You've been assigned as {$this->role} to {$this->project->name}",
        ];
    }
}
