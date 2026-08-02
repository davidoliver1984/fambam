<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationIssued extends Notification
{
    use Queueable;

    public function __construct(public readonly string $acceptUrl) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Family Photo Archive invitation')
            ->greeting('You have been invited')
            ->line('A family member has invited you to create a private Family Photo Archive account.')
            ->action('Accept invitation', $this->acceptUrl)
            ->line('This invitation expires in '.config('invitations.lifetime_days').' days.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
