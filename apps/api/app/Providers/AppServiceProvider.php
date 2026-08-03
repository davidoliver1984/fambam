<?php

namespace App\Providers;

use App\Listeners\SecurityEventSubscriber;
use App\Models\User;
use App\Services\PwnedPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(UncompromisedVerifier::class);
        $this->app->singleton(
            UncompromisedVerifier::class,
            fn ($app): PwnedPasswordVerifier => new PwnedPasswordVerifier(
                $app->make(Factory::class),
                $app->make(LoggerInterface::class),
                (float) config('account-security.compromised_password_check.timeout_seconds'),
            ),
        );

        Password::defaults(function (): Password {
            $rule = Password::min(15)->max(255);

            return config('account-security.compromised_password_check.enabled')
                ? $rule->uncompromised()
                : $rule;
        });

        Gate::define('manage-invitations', static fn (User $user): bool => $user->can_invite === true);
        Event::subscribe(SecurityEventSubscriber::class);
    }
}
