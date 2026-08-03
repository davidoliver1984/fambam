<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEvent;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use LogicException;

class SecurityEventSubscriber
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handleTwoFactorEvent(TwoFactorAuthenticationEvent $event): void
    {
        /** @var User $user */
        $user = $event->user;
        $action = match ($event::class) {
            TwoFactorAuthenticationEnabled::class => 'authentication.mfa_enabled',
            TwoFactorAuthenticationConfirmed::class => 'authentication.mfa_confirmed',
            TwoFactorAuthenticationDisabled::class => 'authentication.mfa_disabled',
            TwoFactorAuthenticationChallenged::class => 'authentication.mfa_challenged',
            TwoFactorAuthenticationFailed::class => 'authentication.mfa_failed',
            ValidTwoFactorAuthenticationCodeProvided::class => 'authentication.mfa_succeeded',
            default => throw new LogicException('Unsupported two-factor security event.'),
        };

        $this->audit->record($action, $user, $user, request());
    }

    public function subscribe(Dispatcher $events): void
    {
        foreach ([
            TwoFactorAuthenticationEnabled::class,
            TwoFactorAuthenticationConfirmed::class,
            TwoFactorAuthenticationDisabled::class,
            TwoFactorAuthenticationChallenged::class,
            TwoFactorAuthenticationFailed::class,
            ValidTwoFactorAuthenticationCodeProvided::class,
        ] as $event) {
            $events->listen($event, $this->handleTwoFactorEvent(...));
        }
    }
}
