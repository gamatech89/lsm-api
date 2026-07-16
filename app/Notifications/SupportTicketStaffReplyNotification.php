<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail-only notification sent on-demand to the ticket's client_email.
 */
class SupportTicketStaffReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SupportTicket $ticket,
        protected SupportTicketMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greetingName = $this->ticket->client_name ?: 'there';

        return (new MailMessage)
            ->subject("Re: [{$this->ticket->ticket_number}] {$this->ticket->subject}")
            ->greeting("Hello {$greetingName},")
            ->line('Our team replied to your support ticket:')
            ->line($this->message->message)
            ->line('To view the conversation or reply, open the Support panel on your website (support button on your site, or the Landeseiten Maintenance page in your WordPress admin).')
            ->salutation('— Landeseiten Support');
    }
}
