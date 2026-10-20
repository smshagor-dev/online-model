<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginIpVerification extends Notification
{
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('OpenMindAI new network sign-in verification')
            ->line('A sign-in was requested from a network we have not seen before.')
            ->line('Your one-time security code: '.$this->code)
            ->line('The code expires in 10 minutes. If you did not attempt to sign in, change your password.');
    }
}
