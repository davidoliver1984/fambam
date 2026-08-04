<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FamilySpaceManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(User $actor, string $name, string $slug, Request $request): FamilySpace
    {
        if (! $actor->can_create_family_spaces) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $name, $slug, $request): FamilySpace {
            $familySpace = FamilySpace::query()->create([
                'name' => $name,
                'slug' => $slug,
                'status' => FamilySpaceStatus::Active,
            ]);
            $membership = $familySpace->memberships()->create([
                'user_id' => $actor->id,
                'role' => FamilySpaceRole::Owner,
                'state' => MembershipState::Active,
            ]);
            $this->audit->record('family_space.created', $familySpace, $actor, $request, [
                'initial_owner_membership_id' => $membership->id,
            ]);

            return $familySpace;
        });
    }

    public function changeRole(
        User $actor,
        FamilySpaceMembership $membership,
        FamilySpaceRole $role,
        ?Request $request = null,
    ): FamilySpaceMembership {
        return DB::transaction(function () use ($actor, $membership, $role, $request): FamilySpaceMembership {
            $target = FamilySpaceMembership::query()->lockForUpdate()->findOrFail($membership->id);
            $actorMembership = $this->activeActorMembership($actor, $target->family_space_id);
            $ownerChange = $target->role === FamilySpaceRole::Owner || $role === FamilySpaceRole::Owner;

            if ($ownerChange && $actorMembership->role !== FamilySpaceRole::Owner) {
                throw new AuthorizationException;
            }

            if (! $ownerChange && ! $actorMembership->role->canManageMembers()) {
                throw new AuthorizationException;
            }

            if ($target->state !== MembershipState::Active) {
                $this->fail('Only an active membership can change role.');
            }

            if ($target->role === FamilySpaceRole::Owner && $role !== FamilySpaceRole::Owner) {
                $this->ensureAnotherOwnerExists($target);
            }

            $previousRole = $target->role;
            $target->update(['role' => $role]);
            $action = match (true) {
                $previousRole !== FamilySpaceRole::Owner && $role === FamilySpaceRole::Owner => 'family_space.owner_promoted',
                $previousRole === FamilySpaceRole::Owner && $role !== FamilySpaceRole::Owner => 'family_space.owner_demoted',
                default => 'family_space.membership_role_changed',
            };
            $this->audit->record($action, $target, $actor, $request, [
                'family_space_id' => $target->family_space_id,
                'previous_role' => $previousRole->value,
                'role' => $role->value,
            ]);

            return $target;
        });
    }

    public function remove(
        User $actor,
        FamilySpaceMembership $membership,
        ?Request $request = null,
    ): FamilySpaceMembership {
        return DB::transaction(function () use ($actor, $membership, $request): FamilySpaceMembership {
            $target = FamilySpaceMembership::query()->lockForUpdate()->findOrFail($membership->id);
            $actorMembership = $this->activeActorMembership($actor, $target->family_space_id);

            if ($target->role === FamilySpaceRole::Owner) {
                if ($actorMembership->role !== FamilySpaceRole::Owner) {
                    throw new AuthorizationException;
                }

                $this->ensureAnotherOwnerExists($target);
            } elseif (! $actorMembership->role->canManageMembers()) {
                throw new AuthorizationException;
            }

            if ($target->state !== MembershipState::Active) {
                $this->fail('This membership is already removed.');
            }

            $target->update([
                'state' => MembershipState::Removed,
                'removed_at' => now(),
                'removed_by' => $actor->id,
            ]);
            $this->audit->record('family_space.member_removed', $target, $actor, $request, [
                'family_space_id' => $target->family_space_id,
                'role' => $target->role->value,
            ]);

            return $target;
        });
    }

    private function activeActorMembership(User $actor, string $familySpaceId): FamilySpaceMembership
    {
        $membership = FamilySpaceMembership::query()
            ->where('family_space_id', $familySpaceId)
            ->where('user_id', $actor->id)
            ->where('state', MembershipState::Active->value)
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    private function ensureAnotherOwnerExists(FamilySpaceMembership $membership): void
    {
        $anotherOwnerExists = FamilySpaceMembership::query()
            ->where('family_space_id', $membership->family_space_id)
            ->whereKeyNot($membership->id)
            ->where('role', FamilySpaceRole::Owner->value)
            ->where('state', MembershipState::Active->value)
            ->lockForUpdate()
            ->exists();

        if (! $anotherOwnerExists) {
            $this->fail('A family space must retain at least one active Owner.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['membership' => [$message]]);
    }
}
