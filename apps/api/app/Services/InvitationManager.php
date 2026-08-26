<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\InvitationStatus;
use App\Enums\MembershipState;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Invitation;
use App\Models\InvitationClaim;
use App\Models\User;
use App\Notifications\InvitationIssued;
use App\Tenancy\DatabaseTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationManager
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly MembershipInvitationAcceptor $membershipAcceptor,
        private readonly DatabaseTenantContext $databaseTenantContext,
        private readonly EventAdmissionManager $admissions,
    ) {}

    public function issue(
        User $actor,
        FamilySpace $familySpace,
        string $email,
        FamilySpaceRole $role,
        Request $request,
        ?FamilyEvent $event = null,
    ): Invitation {
        $email = Str::lower(trim($email));
        $rawToken = $this->newToken();

        try {
            $invitation = DB::transaction(function () use (
                $actor,
                $familySpace,
                $email,
                $role,
                $rawToken,
                $request,
                $event,
            ): Invitation {
                $this->ensureCanManageInvitations($actor, $familySpace);
                $existingUserId = User::query()->where('email', $email)->value('id');

                if ($event === null && $existingUserId !== null && FamilySpaceMembership::query()
                    ->where('family_space_id', $familySpace->id)
                    ->where('user_id', $existingUserId)
                    ->where('state', MembershipState::Active->value)
                    ->exists()) {
                    $this->fail('That account is already an active member of this Family Space.');
                }

                $existing = Invitation::query()
                    ->where('family_space_id', $familySpace->id)
                    ->where('email', $email)
                    ->where('event_id', $event?->id)
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
                    'family_space_id' => $familySpace->id,
                    'email' => $email,
                    'role' => $role,
                    'event_id' => $event?->id,
                    'token_hash' => $this->hashToken($rawToken),
                    'invited_by' => $actor->id,
                    'status' => InvitationStatus::Pending,
                    'expires_at' => now()->addDays((int) config('invitations.lifetime_days')),
                ]);

                $this->audit->record('invitation.issued', $invitation, $actor, $request, [
                    'family_space_id' => $familySpace->id,
                    'role' => $role->value,
                    'event_id' => $event?->id,
                ]);

                return $invitation;
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('A pending invitation already exists for that email address.');
        }

        $this->send($invitation, $rawToken);

        return $invitation;
    }

    public function resend(Invitation $invitation, User $actor, Request $request): Invitation
    {
        $this->ensureCanManageInvitations($actor, $invitation->familySpace);
        if ($invitation->event_id !== null && ! FamilyEvent::query()->whereKey($invitation->event_id)->exists()) {
            $this->fail('An invitation for a removed Event cannot be resent.');
        }
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
        $this->ensureCanManageInvitations($actor, $invitation->familySpace);
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

    /** @return array{claim_token: string, email: string, family_space_name: string, role: string, event: array{id: string, name: string}|null, existing_account: bool, expires_at: string} */
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

            $this->databaseTenantContext->establishFamilySpace($invitation->family_space_id);

            if ($invitation->event_id !== null && ! FamilyEvent::query()->whereKey($invitation->event_id)->exists()) {
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
                'family_space_name' => $invitation->familySpace->name,
                'role' => $invitation->role->value,
                'event' => $invitation->event === null ? null : [
                    'id' => $invitation->event->id,
                    'name' => $invitation->event->name,
                ],
                'existing_account' => User::query()->where('email', $invitation->email)->exists(),
                'expires_at' => $claim->expires_at->toAtomString(),
            ];
        });

        if ($result === null) {
            $this->fail();
        }

        return $result;
    }

    /**
     * @param  array{name?: string|null, password?: string|null, timezone?: string|null}  $attributes
     * @return array{user: User, family_slug: string, event_id: string|null}
     */
    public function accept(string $claimToken, array $attributes, Request $request): array
    {
        $result = DB::transaction(function () use ($claimToken, $attributes, $request): ?array {
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

            $this->databaseTenantContext->establishFamilySpace(
                $invitation->family_space_id,
                authoritativeOperation: 'invitation_acceptance',
            );
            $this->lockAcceptanceKey($invitation->family_space_id);

            if ($invitation->status !== InvitationStatus::Pending) {
                return null;
            }

            if ($invitation->event_id !== null && ! FamilyEvent::query()
                ->where('family_space_id', $invitation->family_space_id)
                ->whereKey($invitation->event_id)
                ->exists()) {
                return null;
            }

            if ($invitation->expires_at->isPast()) {
                $claim->update(['used_at' => now()]);
                $this->expire($invitation, $request);

                return null;
            }

            $user = User::query()->where('email', $invitation->email)->first();
            $createdUser = $user === null;

            if ($user === null) {
                if (! is_string($attributes['name'] ?? null)
                    || ! is_string($attributes['password'] ?? null)
                    || ! is_string($attributes['timezone'] ?? null)) {
                    return null;
                }

                $user = new User;
                $user->forceFill([
                    'name' => $attributes['name'],
                    'email' => $invitation->email,
                    'password' => Hash::make($attributes['password']),
                    'timezone' => $attributes['timezone'],
                    'email_verified_at' => now(),
                    'can_create_family_spaces' => false,
                ])->save();
            } else {
                $requestUser = $request->user('sanctum');
                $password = $attributes['password'] ?? null;
                $mayReuseConcurrentAccount = $invitation->event_id !== null
                    && $requestUser === null
                    && is_string($password)
                    && Hash::check($password, $user->password)
                    && FamilySpaceMembership::query()
                        ->where('family_space_id', $invitation->family_space_id)
                        ->where('user_id', $user->id)
                        ->where('role', FamilySpaceRole::Guest->value)
                        ->where('state', MembershipState::Active->value)
                        ->exists();

                if (($requestUser === null || $requestUser->isNot($user)) && ! $mayReuseConcurrentAccount) {
                    return null;
                }
            }

            $this->databaseTenantContext->establishUser($user);

            $existingMembership = FamilySpaceMembership::query()
                ->where('family_space_id', $invitation->family_space_id)
                ->where('user_id', $user->id)->first();
            $reusedActiveMembership = $invitation->event_id !== null
                && $existingMembership?->state === MembershipState::Active;
            $membershipResult = $reusedActiveMembership
                ? ['membership' => $existingMembership, 'reactivated' => false]
                : $this->membershipAcceptor->accept($invitation, $user);

            if ($membershipResult === null) {
                return null;
            }

            $claim->update(['used_at' => now()]);
            $invitation->update([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => now(),
                'token_hash' => null,
            ]);
            $invitation->claims()->whereKeyNot($claim->id)->delete();
            $this->audit->record('invitation.accepted', $invitation, $user, $request, [
                'family_space_id' => $invitation->family_space_id,
                'user_id' => $user->id,
                'created_user' => $createdUser,
            ]);
            if (! $reusedActiveMembership) {
                $this->audit->record(
                    $membershipResult['reactivated']
                        ? 'family_space.member_reactivated'
                        : 'family_space.member_joined',
                    $membershipResult['membership'],
                    $user,
                    $request,
                    [
                        'family_space_id' => $invitation->family_space_id,
                        'role' => $invitation->role->value,
                        'invitation_id' => $invitation->id,
                    ],
                );
            }
            if ($invitation->event_id !== null) {
                $event = FamilyEvent::query()->where('family_space_id', $invitation->family_space_id)
                    ->findOrFail($invitation->event_id);
                $this->admissions->admit($event, $membershipResult['membership'], $user, $request);
            }

            return [
                'user' => $user,
                'family_slug' => $invitation->familySpace->slug,
                'event_id' => $invitation->event_id,
            ];
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

    public function ensureCanManageInvitations(User $actor, FamilySpace $familySpace): void
    {
        $membership = FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id)
            ->where('user_id', $actor->id)
            ->where('state', MembershipState::Active->value)
            ->first(['role']);

        if ($membership === null) {
            throw (new ModelNotFoundException)->setModel(FamilySpace::class, [$familySpace->id]);
        }

        if (! in_array(
            $membership->role,
            [FamilySpaceRole::Owner, FamilySpaceRole::Administrator],
            true,
        )) {
            throw new AuthorizationException;
        }
    }

    private function newToken(): string
    {
        return Str::random(64);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function lockAcceptanceKey(string $familySpaceId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$familySpaceId]);
        }
    }

    private function fail(string $message = 'This invitation cannot be accepted.'): never
    {
        throw ValidationException::withMessages(['invitation' => [$message]]);
    }
}
