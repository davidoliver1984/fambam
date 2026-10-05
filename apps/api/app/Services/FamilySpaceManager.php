<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Tenancy\DatabaseTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilySpaceManager
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function create(User $actor, string $name, string $slug, Request $request): FamilySpace
    {
        if (! $actor->can_create_family_spaces) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $name, $slug, $request): FamilySpace {
            $familySpaceId = (string) Str::ulid();
            $this->databaseTenantContext->establishUser($actor);
            $this->databaseTenantContext->establishFamilySpace(
                $familySpaceId,
                authoritativeOperation: 'family_space_creation',
            );
            $familySpace = FamilySpace::query()->create([
                'id' => $familySpaceId,
                'name' => $name,
                'slug' => $slug,
                'status' => FamilySpaceStatus::Active,
            ]);
            $membership = $familySpace->memberships()->create([
                'user_id' => $actor->id,
                'role' => FamilySpaceRole::Owner,
                'state' => MembershipState::Active,
                'joined_at' => now(),
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

    /** @param array<string, mixed> $settings */
    public function updateSettings(
        User $actor,
        FamilySpace $familySpace,
        array $settings,
        Request $request,
    ): FamilySpace {
        return DB::transaction(function () use ($actor, $familySpace, $settings, $request): FamilySpace {
            $locked = FamilySpace::query()->lockForUpdate()->findOrFail($familySpace->id);
            $actorMembership = $this->activeActorMembership($actor, $locked->id);
            if (! $actorMembership->role->canManageMembers()) {
                throw new AuthorizationException;
            }

            $original = [
                'name' => $locked->name,
                'description' => $locked->description,
                'default_visibility' => $locked->default_visibility->value,
            ];
            $locked->fill(array_intersect_key($settings, $original));
            $changed = array_keys($locked->getDirty());
            if ($changed === []) {
                return $locked;
            }

            $locked->save();
            if (in_array('name', $changed, true)) {
                $this->audit->record('family_space.name_changed', $locked, $actor, $request, [
                    'from' => $original['name'],
                    'to' => $locked->name,
                ]);
            }
            if (in_array('description', $changed, true)) {
                $this->audit->record('family_space.description_changed', $locked, $actor, $request, [
                    'previously_present' => $original['description'] !== null,
                    'present' => $locked->description !== null,
                ]);
            }
            if (in_array('default_visibility', $changed, true)) {
                $this->audit->record('family_space.default_visibility_changed', $locked, $actor, $request, [
                    'from' => $original['default_visibility'],
                    'to' => $locked->default_visibility->value,
                ]);
            }

            return $locked;
        });
    }

    /** @return array{former_owner: FamilySpaceMembership, owner: FamilySpaceMembership} */
    public function transferOwnership(
        User $actor,
        FamilySpace $familySpace,
        FamilySpaceMembership $membership,
        Request $request,
    ): array {
        return DB::transaction(function () use ($actor, $familySpace, $membership, $request): array {
            FamilySpace::query()->whereKey($familySpace->id)->lockForUpdate()->firstOrFail();
            $actorMembership = $this->activeActorMembership($actor, $familySpace->id);
            if ($actorMembership->role !== FamilySpaceRole::Owner) {
                throw new AuthorizationException;
            }

            $target = FamilySpaceMembership::query()
                ->where('family_space_id', $familySpace->id)
                ->lockForUpdate()
                ->findOrFail($membership->id);
            if ($target->state !== MembershipState::Active) {
                $this->fail('Ownership can be transferred only to an active member.');
            }
            if ($target->id === $actorMembership->id) {
                $this->fail('Choose another active member to receive ownership.');
            }
            if ($target->role === FamilySpaceRole::Owner) {
                $this->fail('The selected member is already an Owner.');
            }

            $target->update(['role' => FamilySpaceRole::Owner]);
            $actorMembership->update(['role' => FamilySpaceRole::Administrator]);
            $this->audit->record('family_space.ownership_transferred', $familySpace, $actor, $request, [
                'former_owner_membership_id' => $actorMembership->id,
                'former_owner_user_id' => $actorMembership->user_id,
                'owner_membership_id' => $target->id,
                'owner_user_id' => $target->user_id,
                'former_owner_role' => FamilySpaceRole::Administrator->value,
            ]);

            return ['former_owner' => $actorMembership, 'owner' => $target];
        });
    }

    public function leave(User $actor, FamilySpace $familySpace, Request $request): FamilySpaceMembership
    {
        return DB::transaction(function () use ($actor, $familySpace, $request): FamilySpaceMembership {
            FamilySpace::query()->whereKey($familySpace->id)->lockForUpdate()->firstOrFail();
            $membership = $this->activeActorMembership($actor, $familySpace->id);
            if ($membership->role === FamilySpaceRole::Owner) {
                $this->fail('Transfer ownership before leaving this Family Space.');
            }

            $this->databaseTenantContext->establishFamilySpace(
                $familySpace,
                authoritativeOperation: 'self_leave',
            );
            $membership->update([
                'state' => MembershipState::Removed,
                'removed_at' => now(),
                'removed_by' => $actor->id,
            ]);
            $this->audit->record('family_space.member_left', $membership, $actor, $request, [
                'family_space_id' => $familySpace->id,
                'role' => $membership->role->value,
            ]);

            return $membership;
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
