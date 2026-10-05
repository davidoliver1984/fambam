<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailChangeManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function request(User $user, string $email, Request $request): void
    {
        $normalized = Str::lower(trim($email));
        if (hash_equals(Str::lower($user->email), $normalized)) {
            throw ValidationException::withMessages(['email' => ['The new email must be different from the current email.']]);
        }

        DB::transaction(function () use ($user, $normalized, $request): void {
            $user->forceFill([
                'pending_email' => $normalized,
                'pending_email_requested_at' => now(),
            ])->save();
            $this->audit->record('account.email_change_requested', $user, $user, $request);
        });

        Notification::route('mail', $normalized)->notify(new VerifyPendingEmail(
            URL::temporarySignedRoute(
                'account.email.verify',
                now()->addMinutes(60),
                ['user' => $user->id, 'hash' => sha1($normalized)],
            ),
        ));
    }

    public function verify(User $actor, int $userId, string $hash, Request $request): User
    {
        abort_unless($actor->id === $userId, 403);

        $verified = DB::transaction(function () use ($actor, $hash, $request): User {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $pendingEmail = $user->pending_email;
            abort_if($pendingEmail === null || ! hash_equals(sha1($pendingEmail), $hash), 403);
            if ($user->pending_email_requested_at === null || $user->pending_email_requested_at->lt(now()->subMinutes(60))) {
                abort(403, 'This email verification request has expired.');
            }
            if (User::query()->where('email', $pendingEmail)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['email' => ['That email address is no longer available.']]);
            }

            $oldEmail = $user->email;
            $user->forceFill([
                'email' => $pendingEmail,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_requested_at' => null,
            ])->save();
            $this->audit->record('account.email_changed', $user, $user, $request, [
                'old_email_hash' => hash('sha256', Str::lower($oldEmail)),
            ]);

            return $user;
        });

        event(new Verified($verified));

        return $verified;
    }
}
