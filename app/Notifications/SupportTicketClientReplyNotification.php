<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportTicketClientReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("💬 Client replied on {$this->ticket->ticket_number}: {$this->ticket->subject}")
            ->greeting("New client reply on {$this->ticket->project->name}")
            ->line("**From:** {$this->message->author_name}")
            ->line($this->message->message)
            ->action('View Ticket', config('app.frontend_url') . "/projects/{$this->ticket->project_id}?section=support")
            ->salutation('— Landeseiten Maintenance');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_client_reply',
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'project_id' => $this->ticket->project_id,
            'project_name' => $this->ticket->project->name,
            'subject' => $this->ticket->subject,
            'message_id' => $this->message->id,
        ];
    }
}
