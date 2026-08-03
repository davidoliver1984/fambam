<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\EnumerationSafePasswordResetLinkResponse;
use App\Http\Responses\OneTimeRecoveryCodesGeneratedResponse;
use App\Models\User;
use App\Services\AuthenticateUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            EnumerationSafePasswordResetLinkResponse::class,
        );
        $this->app->singleton(
            RecoveryCodesGeneratedResponse::class,
            OneTimeRecoveryCodesGeneratedResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(fn (Request $request): ?User => app(AuthenticateUser::class)->attempt(
            (string) $request->input(Fortify::username()),
            (string) $request->input('password'),
        ));

        ResetPassword::createUrlUsing(static fn (CanResetPassword $user, string $token): string => sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('app.web_url'), '/'),
            urlencode($token),
            urlencode((string) $user->getEmailForPasswordReset()),
        ));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->session()->get('login.id');

            return Limit::perMinute(5)->by(
                $loginId === null ? 'ip:'.$request->ip() : 'user:'.$loginId,
            );
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('password-reset-submission', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('account-security', fn (Request $request) => Limit::perMinute(6)->by(
            $request->user() === null ? 'ip:'.$request->ip() : 'user:'.$request->user()->id,
        ));

        RateLimiter::for('invitation-acceptance', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('invitation-issuance', fn (Request $request) => Limit::perHour(10)->by(
            (string) ($request->user()->id ?: $request->ip()),
        ));

    }
}
