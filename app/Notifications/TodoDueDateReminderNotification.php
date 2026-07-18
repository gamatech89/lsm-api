<?php

namespace App\Notifications;

use App\Models\Todo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TodoDueDateReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Todo $todo
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dueDate = $this->todo->due_date->format('M d, Y');
        
        return (new MailMessage)
            ->subject("⏰ Todo Due Tomorrow: {$this->todo->title}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your assigned todo is due tomorrow:")
            ->line("**Title:** {$this->todo->title}")
            ->line("**Project:** {$this->todo->project->name}")
            ->line("**Due Date:** {$dueDate}")
            ->action('View Project', config('app.frontend_url') . "/projects/{$this->todo->project_id}?section=todos")
            ->line('Please make sure to complete it before the deadline.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'todo_id' => $this->todo->id,
            'todo_title' => $this->todo->title,
            'project_id' => $this->todo->project_id,
            'project_name' => $this->todo->project->name,
            'due_date' => $this->todo->due_date->toISOString(),
            'message' => "⏰ Todo due tomorrow: {$this->todo->title}",
        ];
    }
}
