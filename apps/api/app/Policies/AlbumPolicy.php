<?php

namespace App\Policies;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\User;
use App\Services\EventAccess;
use App\Tenancy\TenantContext;

class AlbumPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EventAccess $eventAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->member($user)
            && $this->tenantContext->membership()->role !== FamilySpaceRole::Guest;
    }

    public function create(User $user): bool
    {
        return $this->member($user) && ! in_array($this->tenantContext->membership()->role, [
            FamilySpaceRole::Contributor,
            FamilySpaceRole::Guest,
        ], true);
    }

    public function view(User $user, Album $album): bool
    {
        if (! $this->matches($user, $album)) {
            return false;
        }
        $membership = $this->tenantContext->membership();
        if ($membership->role === FamilySpaceRole::Guest) {
            return $this->eventAccess->guestMayViewAlbum($album, $membership);
        }
        if ($membership->role->canManageMembers() || $album->created_by === $user->id) {
            return true;
        }
        if ($album->visibility === AlbumVisibility::FamilySpace && $membership->role === FamilySpaceRole::Member) {
            return true;
        }

        return $album->visibility === AlbumVisibility::Selected
            && AlbumGrant::query()->where('album_id', $album->id)
                ->where('family_space_membership_id', $membership->id)->where('can_view', true)->exists();
    }

    public function update(User $user, Album $album): bool
    {
        return $this->manage($user, $album);
    }

    public function manageGrants(User $user, Album $album): bool
    {
        return $this->manage($user, $album);
    }

    public function addPhoto(User $user, Album $album): bool
    {
        return $this->contribute($user, $album)
            && $this->tenantContext->membership()->role !== FamilySpaceRole::Guest;
    }

    public function removePhoto(User $user, Album $album): bool
    {
        return $this->contribute($user, $album)
            && $this->tenantContext->membership()->role !== FamilySpaceRole::Guest;
    }

    public function contribute(User $user, Album $album): bool
    {
        if (! $this->matches($user, $album)) {
            return false;
        }
        $membership = $this->tenantContext->membership();
        if ($membership->role === FamilySpaceRole::Guest) {
            return $this->eventAccess->guestMayContributeToAlbum($album, $membership);
        }
        if ($membership->role->canManageMembers() || $album->created_by === $user->id) {
            return true;
        }
        if ($album->visibility === AlbumVisibility::FamilySpace && $membership->role === FamilySpaceRole::Member) {
            return true;
        }

        return AlbumGrant::query()->where('album_id', $album->id)
            ->where('family_space_membership_id', $membership->id)
            ->where('can_view', true)
            ->where('can_contribute', true)
            ->exists();
    }

    private function manage(User $user, Album $album): bool
    {
        return $this->matches($user, $album)
            && $this->tenantContext->membership()->role !== FamilySpaceRole::Guest
            && ($this->tenantContext->membership()->role->canManageMembers() || $album->created_by === $user->id);
    }

    private function member(User $user): bool
    {
        return $this->tenantContext->isEstablished()
            && $this->tenantContext->membership()->user_id === $user->id
            && $this->tenantContext->membership()->state === MembershipState::Active;
    }

    private function matches(User $user, Album $album): bool
    {
        return $this->member($user) && $album->family_space_id === $this->tenantContext->familySpace()->id;
    }
}
