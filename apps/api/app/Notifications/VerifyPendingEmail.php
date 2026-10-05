<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyPendingEmail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $verificationUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your new Fambam email address')
            ->line('Confirm this email address to finish changing your Fambam sign-in email.')
            ->action('Verify email address', $this->verificationUrl)
            ->line('Your current email remains active until you verify this one.');
    }
}
