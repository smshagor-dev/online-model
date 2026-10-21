<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailOtpCode extends Notification
{
    public function __construct(public string $code, public string $purpose) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $action = $this->purpose === 'registration' ? 'verify your email address' : 'reset your password';

        return (new MailMessage)
            ->subject('OpenMindAI security code')
            ->line('Use this six-digit code to '.$action.':')
            ->line($this->code)
            ->line('This code expires in 10 minutes. Do not share it with anyone.');
    }
}
