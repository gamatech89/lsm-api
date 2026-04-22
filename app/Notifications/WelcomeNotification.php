<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    use Queueable;

    public string $token;
    public string $plainPassword;

    public function __construct(string $token, string $plainPassword)
    {
        $this->token = $token;
        $this->plainPassword = $plainPassword;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Welcome to ' . config('app.name') . ' — Your Account is Ready')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('An account has been created for you on ' . config('app.name') . '.')
            ->line('**Login URL:** ' . $frontendUrl)
            ->line('**Email:** ' . $notifiable->email)
            ->line('**Temporary Password:** ' . $this->plainPassword)
            ->action('Set Your Own Password', $resetUrl)
            ->line('We recommend setting your own password using the button above.')
            ->line('If you have any questions, contact your administrator.');
    }
}
