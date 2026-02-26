<?php

namespace App\Notifications;

use App\Models\Todo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TodoAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Todo $todo,
        protected string $addedByName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $priorityLabel = match($this->todo->priority) {
            2 => '🔴 High',
            1 => '🟡 Medium',
            default => '🟢 Low',
        };
        
        return (new MailMessage)
            ->subject("New Todo Added: {$this->todo->project->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("A new todo has been added to a project you're assigned to:")
            ->line("**Project:** {$this->todo->project->name}")
            ->line("**Todo:** {$this->todo->title}")
            ->line("**Priority:** {$priorityLabel}")
            ->line("**Added by:** {$this->addedByName}")
            ->when($this->todo->due_date, function ($mail) {
                return $mail->line("**Due Date:** {$this->todo->due_date->format('M d, Y')}");
            })
            ->action('View Project', config('app.frontend_url') . "/projects/{$this->todo->project_id}"))
            ->line('Please check the project for details.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'todo_id' => $this->todo->id,
            'todo_title' => $this->todo->title,
            'project_id' => $this->todo->project_id,
            'project_name' => $this->todo->project->name,
            'added_by' => $this->addedByName,
            'message' => "New todo added to {$this->todo->project->name}: {$this->todo->title}",
        ];
    }
}
