<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail-only notification sent on-demand to the ticket's client_email.
 */
class SupportTicketResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greetingName = $this->ticket->client_name ?: 'there';

        $mail = (new MailMessage)
            ->subject("✅ Resolved: [{$this->ticket->ticket_number}] {$this->ticket->subject}")
            ->greeting("Hello {$greetingName},")
            ->line("Your support ticket **{$this->ticket->ticket_number}** has been resolved.");

        if ($this->ticket->resolution_notes) {
            $mail->line($this->ticket->resolution_notes);
        }

        return $mail
            ->line('If the problem persists, just reply from the Support panel on your website and the ticket will be reopened.')
            ->salutation('— Landeseiten Support');
    }
}
