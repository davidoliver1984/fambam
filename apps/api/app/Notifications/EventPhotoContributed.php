<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventPhotoContributed extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $eventName,
        public readonly string $contributorName,
        public readonly string $eventUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("A new photograph was added to {$this->eventName}")
            ->greeting('A new family photograph is ready')
            ->line("{$this->contributorName} added a photograph to {$this->eventName}.")
            ->action('View Event', $this->eventUrl)
            ->line('You are receiving this because you organise this Family Space or currently participate in this Event.');
    }
}
