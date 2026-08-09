<?php

namespace App\Services;

use App\Enums\MembershipState;
use App\Enums\PersonAccountClaimStatus;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountClaim;
use App\Models\PersonAccountLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonAccountLinkManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function propose(Person $person, User $actor, Request $request): PersonAccountClaim
    {
        try {
            return DB::transaction(function () use ($person, $actor, $request): PersonAccountClaim {
                $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
                $this->ensureLinkAvailable($lockedPerson, $actor->id);

                $claim = PersonAccountClaim::query()->create([
                    'family_space_id' => $lockedPerson->family_space_id,
                    'person_id' => $lockedPerson->id,
                    'user_id' => $actor->id,
                    'status' => PersonAccountClaimStatus::Pending,
                    'proposed_by' => $actor->id,
                ]);
                $this->audit->record('person.account_link_claim_proposed', $claim, $actor, $request, [
                    'person_id' => $lockedPerson->id,
                ]);

                return $claim;
            });
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    public function approve(
        Person $person,
        PersonAccountClaim $claim,
        User $actor,
        Request $request,
    ): PersonAccountLink {
        try {
            return DB::transaction(function () use ($person, $claim, $actor, $request): PersonAccountLink {
                $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
                $lockedClaim = PersonAccountClaim::query()->lockForUpdate()->findOrFail($claim->id);
                $this->ensurePendingClaimFor($lockedClaim, $lockedPerson);

                $membershipActive = FamilySpaceMembership::query()
                    ->where('family_space_id', $lockedPerson->family_space_id)
                    ->where('user_id', $lockedClaim->user_id)
                    ->where('state', MembershipState::Active->value)
                    ->lockForUpdate()
                    ->exists();
                if (! $membershipActive) {
                    $this->fail('The claimant no longer has an active Family Space membership.');
                }

                $this->ensureLinkAvailable($lockedPerson, $lockedClaim->user_id);
                $link = PersonAccountLink::query()->create([
                    'family_space_id' => $lockedPerson->family_space_id,
                    'person_id' => $lockedPerson->id,
                    'user_id' => $lockedClaim->user_id,
                    'created_by' => $actor->id,
                ]);
                $lockedClaim->update([
                    'status' => PersonAccountClaimStatus::Approved,
                    'resolved_by' => $actor->id,
                    'resolved_at' => CarbonImmutable::now(),
                ]);
                $this->rejectConflictingClaims(
                    $lockedPerson->family_space_id,
                    $lockedClaim->person_id,
                    $lockedClaim->user_id,
                    $lockedClaim->id,
                    $actor,
                    $request,
                );
                $this->audit->record('person.account_link_claim_approved', $lockedClaim, $actor, $request, [
                    'person_id' => $lockedPerson->id,
                    'link_id' => $link->id,
                ]);
                $this->audit->record('person.account_link_created', $link, $actor, $request, [
                    'person_id' => $lockedPerson->id,
                    'source' => 'approved_self_claim',
                ]);

                return $link;
            });
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    public function reject(
        Person $person,
        PersonAccountClaim $claim,
        User $actor,
        Request $request,
    ): PersonAccountClaim {
        return DB::transaction(function () use ($person, $claim, $actor, $request): PersonAccountClaim {
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
            $lockedClaim = PersonAccountClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->ensurePendingClaimFor($lockedClaim, $lockedPerson);
            $lockedClaim->update([
                'status' => PersonAccountClaimStatus::Rejected,
                'resolved_by' => $actor->id,
                'resolved_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record('person.account_link_claim_rejected', $lockedClaim, $actor, $request, [
                'person_id' => $lockedPerson->id,
            ]);

            return $lockedClaim;
        });
    }

    public function assign(
        Person $person,
        FamilySpaceMembership $membership,
        User $actor,
        Request $request,
    ): PersonAccountLink {
        try {
            return DB::transaction(function () use ($person, $membership, $actor, $request): PersonAccountLink {
                $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
                $lockedMembership = FamilySpaceMembership::query()->lockForUpdate()->findOrFail($membership->id);
                if ($lockedMembership->family_space_id !== $lockedPerson->family_space_id
                    || $lockedMembership->state !== MembershipState::Active) {
                    $this->fail('Only an active membership in this Family Space may be linked.');
                }

                $byPerson = PersonAccountLink::query()->where('person_id', $lockedPerson->id)->lockForUpdate()->first();
                $byUser = PersonAccountLink::query()
                    ->where('family_space_id', $lockedPerson->family_space_id)
                    ->where('user_id', $lockedMembership->user_id)
                    ->lockForUpdate()
                    ->first();
                if ($byPerson?->id === $byUser?->id && $byPerson !== null) {
                    return $byPerson;
                }

                $previousPersonLinkId = $byPerson?->id;
                $previousUserLinkId = $byUser?->id;
                $byPerson?->delete();
                if ($byUser !== null && $byUser->id !== $byPerson?->id) {
                    $byUser->delete();
                }

                $link = PersonAccountLink::query()->create([
                    'family_space_id' => $lockedPerson->family_space_id,
                    'person_id' => $lockedPerson->id,
                    'user_id' => $lockedMembership->user_id,
                    'created_by' => $actor->id,
                ]);
                $this->rejectConflictingClaims(
                    $lockedPerson->family_space_id,
                    $lockedPerson->id,
                    $lockedMembership->user_id,
                    null,
                    $actor,
                    $request,
                );
                $this->audit->record(
                    $previousPersonLinkId === null && $previousUserLinkId === null
                        ? 'person.account_link_created'
                        : 'person.account_link_corrected',
                    $link,
                    $actor,
                    $request,
                    [
                        'person_id' => $lockedPerson->id,
                        'previous_person_link_id' => $previousPersonLinkId,
                        'previous_user_link_id' => $previousUserLinkId,
                    ],
                );

                return $link;
            });
        } catch (QueryException $exception) {
            $this->translateUniqueConflict($exception);
        }
    }

    public function unlink(Person $person, User $actor, Request $request): void
    {
        DB::transaction(function () use ($person, $actor, $request): void {
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
            $link = PersonAccountLink::query()
                ->where('person_id', $lockedPerson->id)
                ->lockForUpdate()
                ->first();
            if ($link === null) {
                return;
            }

            $this->audit->record('person.account_link_removed', $link, $actor, $request, [
                'person_id' => $lockedPerson->id,
                'linked_user_id' => $link->user_id,
            ]);
            $link->delete();
        });
    }

    private function ensureLinkAvailable(Person $person, int $userId): void
    {
        if (PersonAccountLink::query()->where('person_id', $person->id)->exists()) {
            $this->fail('This Person is already linked to an account.');
        }
        if (PersonAccountLink::query()
            ->where('family_space_id', $person->family_space_id)
            ->where('user_id', $userId)
            ->exists()) {
            $this->fail('This account is already linked to a Person in this Family Space.');
        }
    }

    private function ensurePendingClaimFor(PersonAccountClaim $claim, Person $person): void
    {
        if ($claim->person_id !== $person->id || $claim->status !== PersonAccountClaimStatus::Pending) {
            $this->fail('This self-claim can no longer be resolved.');
        }
    }

    private function rejectConflictingClaims(
        string $familySpaceId,
        string $personId,
        int $userId,
        ?string $excludedClaimId,
        User $actor,
        Request $request,
    ): void {
        $conflicts = PersonAccountClaim::query()
            ->where('family_space_id', $familySpaceId)
            ->where('status', PersonAccountClaimStatus::Pending->value)
            ->when($excludedClaimId !== null, fn ($query) => $query->whereKeyNot($excludedClaimId))
            ->where(function ($query) use ($personId, $userId): void {
                $query->where('person_id', $personId)
                    ->orWhere('user_id', $userId);
            })
            ->lockForUpdate()
            ->get();

        foreach ($conflicts as $conflict) {
            $conflict->update([
                'status' => PersonAccountClaimStatus::Rejected,
                'resolved_by' => $actor->id,
                'resolved_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record('person.account_link_claim_rejected', $conflict, $actor, $request, [
                'person_id' => $conflict->person_id,
                'reason' => 'authoritative_link_established',
            ]);
        }
    }

    private function translateUniqueConflict(QueryException $exception): never
    {
        if (in_array($exception->getCode(), ['23000', '23505'], true)) {
            $this->fail('This Person or account already has a link or pending self-claim.');
        }

        throw $exception;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['account_link' => [$message]]);
    }
}
