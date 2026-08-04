<?php

namespace App\Providers;

use App\Listeners\SecurityEventSubscriber;
use App\Services\AuthenticateUser;
use App\Services\PwnedPasswordVerifier;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(
            AuthenticateUser::class,
            fn ($app): AuthenticateUser => new AuthenticateUser(
                $app->make(Hasher::class),
                (string) config('account-security.dummy_password_hash'),
            ),
        );

        $this->app->extend(
            UncompromisedVerifier::class,
            fn ($verifier, $app): PwnedPasswordVerifier => new PwnedPasswordVerifier(
                $app->make(Factory::class),
                $app->make(LoggerInterface::class),
                (float) config('account-security.compromised_password_check.timeout_seconds'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(15)->max(255);

            return config('account-security.compromised_password_check.enabled')
                ? $rule->uncompromised()
                : $rule;
        });

        Event::subscribe(SecurityEventSubscriber::class);
    }
}
