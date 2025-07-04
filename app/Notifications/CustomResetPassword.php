<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CustomResetPassword extends Notification
{
    public $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $url = "https://your-frontend.com/reset-password?token={$this->token}&email=" . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Reset Your Password')
            ->line('You requested a password reset.')
            ->action('Reset Password', $url)
            ->line('If you did not request a password reset, no further action is required.');
    }
}
