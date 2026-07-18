<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportTicketReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->ticket->project;

        return (new MailMessage)
            ->subject("🎫 New Ticket {$this->ticket->ticket_number}: {$this->ticket->subject}")
            ->greeting("New support ticket for {$project->name}")
            ->line("**Type:** {$this->ticket->type_label}")
            ->line('**Priority:** ' . ucfirst($this->ticket->priority))
            ->line("**From:** {$this->ticket->client_name} <{$this->ticket->client_email}>")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line($this->ticket->message)
            ->action('View Ticket', config('app.frontend_url') . "/projects/{$this->ticket->project_id}?section=support")
            ->salutation('— Landeseiten Maintenance');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_received',
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'project_id' => $this->ticket->project_id,
            'project_name' => $this->ticket->project->name,
            'subject' => $this->ticket->subject,
            'priority' => $this->ticket->priority,
        ];
    }
}
