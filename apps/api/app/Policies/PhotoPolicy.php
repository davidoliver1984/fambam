<?php

namespace App\Policies;

use App\Enums\FamilySpaceRole;
use App\Enums\PhotoVisibility;
use App\Models\Photo;
use App\Models\User;
use App\Tenancy\TenantContext;

class PhotoPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->hasPhotoDirectoryAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->hasPhotoDirectoryAccess($user);
    }

    public function view(User $user, Photo $photo): bool
    {
        if ($photo->trashed() || ! $this->matchesContext($user, $photo)) {
            return false;
        }

        $role = $this->tenantContext->membership()->role;

        return $role->canManageMembers()
            || ($photo->visibility === PhotoVisibility::FamilySpace && $role === FamilySpaceRole::Member)
            || $photo->created_by === $user->id
            || $this->hasAlbumAccess($photo);
    }

    public function update(User $user, Photo $photo): bool
    {
        return $this->matchesContext($user, $photo)
            && ($this->tenantContext->membership()->role->canManageMembers()
                || $photo->created_by === $user->id);
    }

    public function proposeProvenance(User $user, Photo $photo): bool
    {
        return $this->view($user, $photo) && $this->hasPhotoDirectoryAccess($user);
    }

    public function resolveProvenance(User $user, Photo $photo): bool
    {
        return $this->matchesContext($user, $photo)
            && $this->tenantContext->membership()->role->canManageMembers();
    }

    public function manageTags(User $user, Photo $photo): bool
    {
        return $this->view($user, $photo) && $this->hasPhotoDirectoryAccess($user);
    }

    public function interact(User $user, Photo $photo): bool
    {
        if (! $this->view($user, $photo)) {
            return false;
        }

        $role = $this->tenantContext->membership()->role;

        return $role !== FamilySpaceRole::Guest
            && ($role !== FamilySpaceRole::Contributor || $this->hasAlbumContributionAccess($photo));
    }

    public function delete(User $user, Photo $photo): bool
    {
        return ! $photo->trashed() && $this->mayManageTombstone($user, $photo);
    }

    public function restore(User $user, Photo $photo): bool
    {
        return $photo->trashed() && $this->mayManageTombstone($user, $photo);
    }

    private function hasPhotoDirectoryAccess(User $user): bool
    {
        if (! $this->tenantContext->isEstablished()
            || $this->tenantContext->membership()->user_id !== $user->id) {
            return false;
        }

        return in_array($this->tenantContext->membership()->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true);
    }

    private function matchesContext(User $user, Photo $photo): bool
    {
        return $this->tenantContext->isEstablished()
            && $this->tenantContext->membership()->user_id === $user->id
            && $this->tenantContext->familySpace()->id === $photo->family_space_id;
    }

    private function mayManageTombstone(User $user, Photo $photo): bool
    {
        if (! $this->matchesContext($user, $photo)
            || $this->tenantContext->membership()->role === FamilySpaceRole::Guest) {
            return false;
        }

        return $this->tenantContext->membership()->role->canManageMembers()
            || $photo->created_by === $user->id;
    }

    private function hasAlbumAccess(Photo $photo): bool
    {
        $membership = $this->tenantContext->membership();

        if ($membership->role === FamilySpaceRole::Guest) {
            return false;
        }

        return $photo->albums()->where(function ($query) use ($membership): void {
            $query->where(function ($family) use ($membership): void {
                $family->where('albums.visibility', 'family_space');
                if ($membership->role !== FamilySpaceRole::Member) {
                    $family->whereRaw('1 = 0');
                }
            })->orWhere('albums.created_by', $membership->user_id)
                ->orWhereExists(function ($grant) use ($membership): void {
                    $grant->selectRaw('1')->from('album_grants')
                        ->whereColumn('album_grants.album_id', 'albums.id')
                        ->where('album_grants.family_space_membership_id', $membership->id)
                        ->where('album_grants.can_view', true);
                });
        })->exists();
    }

    private function hasAlbumContributionAccess(Photo $photo): bool
    {
        $membership = $this->tenantContext->membership();

        return $membership->role !== FamilySpaceRole::Guest
            && $photo->albums()->whereHas('grants', fn ($grant) => $grant
                ->where('family_space_membership_id', $membership->id)
                ->where('can_view', true)
                ->where('can_contribute', true))
                ->exists();
    }
}
