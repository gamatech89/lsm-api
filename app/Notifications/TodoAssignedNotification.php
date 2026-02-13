<?php

namespace App\Notifications;

use App\Models\Todo;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TodoAssignedNotification extends Notification
{

    public function __construct(
        protected Todo $todo
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
            ->subject("New Todo Assigned: {$this->todo->title}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("You have been assigned a new todo:")
            ->line("**Title:** {$this->todo->title}")
            ->line("**Project:** {$this->todo->project->name}")
            ->line("**Priority:** {$priorityLabel}")
            ->when($this->todo->due_date, function ($mail) {
                return $mail->line("**Due Date:** {$this->todo->due_date->format('M d, Y')}");
            })
            ->action('View Project', url("/projects/{$this->todo->project_id}"))
            ->line('Please complete this task as soon as possible.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'todo_id' => $this->todo->id,
            'todo_title' => $this->todo->title,
            'project_id' => $this->todo->project_id,
            'project_name' => $this->todo->project->name,
            'message' => "You've been assigned todo: {$this->todo->title}",
        ];
    }
}
