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
        if (! $this->matchesContext($user, $photo)) {
            return false;
        }

        $role = $this->tenantContext->membership()->role;

        return $role->canManageMembers()
            || $photo->visibility === PhotoVisibility::FamilySpace
            || $photo->created_by === $user->id;
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
}
