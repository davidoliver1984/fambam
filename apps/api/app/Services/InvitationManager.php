<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\InvitationClaim;
use App\Models\User;
use App\Notifications\InvitationIssued;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function issue(User $actor, string $email, Request $request): Invitation
    {
        $email = Str::lower(trim($email));
        $rawToken = $this->newToken();

        $invitation = DB::transaction(function () use ($actor, $email, $rawToken, $request): Invitation {
            if (User::query()->where('email', $email)->exists()) {
                $this->fail('An account already exists for that email address.');
            }

            $existing = Invitation::query()
                ->where('email', $email)
                ->where('status', InvitationStatus::Pending->value)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing !== null && $existing->expires_at->isFuture()) {
                $this->fail('A pending invitation already exists for that email address.');
            }

            if ($existing !== null) {
                $this->expire($existing, $request, $actor);
            }

            $invitation = Invitation::query()->create([
                'email' => $email,
                'token_hash' => $this->hashToken($rawToken),
                'invited_by' => $actor->id,
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays((int) config('invitations.lifetime_days')),
            ]);

            $this->audit->record('invitation.issued', $invitation, $actor, $request);

            return $invitation;
        });

        $this->send($invitation, $rawToken);

        return $invitation;
    }

    public function resend(Invitation $invitation, User $actor, Request $request): Invitation
    {
        $rawToken = $this->newToken();

        $result = DB::transaction(function () use ($invitation, $actor, $request, $rawToken): ?Invitation {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->status !== InvitationStatus::Pending) {
                return null;
            }

            if ($locked->expires_at->isPast()) {
                $this->expire($locked, $request, $actor);

                return null;
            }

            $locked->claims()->delete();
            $locked->update([
                'token_hash' => $this->hashToken($rawToken),
                'expires_at' => now()->addDays((int) config('invitations.lifetime_days')),
            ]);
            $this->audit->record('invitation.resent', $locked, $actor, $request);

            return $locked;
        });

        if ($result === null) {
            $this->fail();
        }

        $this->send($result, $rawToken);

        return $result;
    }

    public function revoke(Invitation $invitation, User $actor, Request $request): Invitation
    {
        $result = DB::transaction(function () use ($invitation, $actor, $request): ?Invitation {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->status !== InvitationStatus::Pending) {
                return null;
            }

            if ($locked->expires_at->isPast()) {
                $this->expire($locked, $request, $actor);

                return null;
            }

            $locked->claims()->delete();
            $locked->update([
                'status' => InvitationStatus::Revoked,
                'token_hash' => null,
                'revoked_at' => now(),
            ]);
            $this->audit->record('invitation.revoked', $locked, $actor, $request);

            return $locked;
        });

        if ($result === null) {
            $this->fail();
        }

        return $result;
    }

    /** @return array{claim_token: string, email: string, expires_at: string} */
    public function exchange(string $rawToken, Request $request): array
    {
        $claimToken = $this->newToken();

        $result = DB::transaction(function () use ($rawToken, $claimToken, $request): ?array {
            $invitation = Invitation::query()
                ->where('token_hash', $this->hashToken($rawToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->status !== InvitationStatus::Pending) {
                return null;
            }

            if ($invitation->expires_at->isPast()) {
                $this->expire($invitation, $request);

                return null;
            }

            $invitation->update(['token_hash' => null]);
            $claim = $invitation->claims()->create([
                'token_hash' => $this->hashToken($claimToken),
                'expires_at' => now()->addMinutes((int) config('invitations.claim_lifetime_minutes')),
            ]);

            return [
                'claim_token' => $claimToken,
                'email' => $invitation->email,
                'expires_at' => $claim->expires_at->toAtomString(),
            ];
        });

        if ($result === null) {
            $this->fail();
        }

        return $result;
    }

    /** @param array{name: string, password: string, timezone: string} $attributes */
    public function accept(string $claimToken, array $attributes, Request $request): User
    {
        $result = DB::transaction(function () use ($claimToken, $attributes, $request): ?User {
            $claimReference = InvitationClaim::query()
                ->where('token_hash', $this->hashToken($claimToken))
                ->whereNull('used_at')
                ->first();

            if ($claimReference === null) {
                return null;
            }

            $invitation = Invitation::query()->lockForUpdate()->find($claimReference->invitation_id);

            $claim = InvitationClaim::query()
                ->whereKey($claimReference->id)
                ->where('token_hash', $this->hashToken($claimToken))
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $claim === null || $claim->expires_at->isPast()) {
                return null;
            }

            if ($invitation->status !== InvitationStatus::Pending) {
                return null;
            }

            if ($invitation->expires_at->isPast()) {
                $claim->update(['used_at' => now()]);
                $this->expire($invitation, $request);

                return null;
            }

            if (User::query()->where('email', $invitation->email)->exists()) {
                return null;
            }

            $user = new User;
            $user->forceFill([
                'name' => $attributes['name'],
                'email' => $invitation->email,
                'password' => Hash::make($attributes['password']),
                'timezone' => $attributes['timezone'],
                'email_verified_at' => now(),
                'can_invite' => false,
            ])->save();

            $claim->update(['used_at' => now()]);
            $invitation->update([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => now(),
                'token_hash' => null,
            ]);
            $invitation->claims()->whereKeyNot($claim->id)->delete();
            $this->audit->record('invitation.accepted', $invitation, $user, $request, [
                'created_user_id' => $user->id,
            ]);

            return $user;
        });

        if ($result === null) {
            $this->fail();
        }

        return $result;
    }

    private function expire(Invitation $invitation, Request $request, ?User $actor = null): void
    {
        $invitation->claims()->delete();
        $invitation->update([
            'status' => InvitationStatus::Expired,
            'token_hash' => null,
        ]);
        $this->audit->record('invitation.expired', $invitation, $actor, $request);
    }

    private function send(Invitation $invitation, string $rawToken): void
    {
        $url = sprintf(
            '%s/accept-invitation#token=%s',
            rtrim((string) config('app.web_url'), '/'),
            rawurlencode($rawToken),
        );

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationIssued($url));
    }

    private function newToken(): string
    {
        return Str::random(64);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function fail(string $message = 'This invitation cannot be accepted.'): never
    {
        throw ValidationException::withMessages(['invitation' => [$message]]);
    }
}
