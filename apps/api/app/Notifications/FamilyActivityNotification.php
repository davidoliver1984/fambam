<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FamilyActivityNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $message, private readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('New activity in fambam')->line($this->message)->action('Open fambam', $this->url);
    }
}
