<?php

namespace App\Policies;

use App\Enums\FamilySpaceRole;
use App\Enums\PhotoVisibility;
use App\Models\MediaUpload;
use App\Models\User;
use App\Tenancy\TenantContext;

class MediaUploadPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function create(User $user): bool
    {
        return $this->hasPhaseFiveMediaAccess($user);
    }

    public function viewBatch(User $user): bool
    {
        return $this->hasPhaseFiveMediaAccess($user);
    }

    public function complete(User $user, MediaUpload $upload): bool
    {
        return $this->hasPhaseFiveMediaAccess($user)
            && $this->tenantContext->familySpace()->id === $upload->family_space_id
            && $upload->user_id === $user->id;
    }

    public function view(User $user, MediaUpload $upload): bool
    {
        if (! $this->matchesContext($user, $upload) || ! $this->hasPhaseFiveMediaAccess($user)) {
            return false;
        }

        $upload->loadMissing('photo');
        if ($upload->photo === null) {
            return true;
        }

        return $this->tenantContext->membership()->role->canManageMembers()
            || $upload->photo->visibility === PhotoVisibility::FamilySpace
            || $upload->photo->created_by === $user->id;
    }

    public function downloadOriginal(User $user, MediaUpload $upload): bool
    {
        return $this->view($user, $upload);
    }

    public function retryProcessing(User $user, MediaUpload $upload): bool
    {
        return $this->view($user, $upload)
            && ($this->tenantContext->membership()->role->canManageMembers()
                || $upload->user_id === $user->id);
    }

    private function hasPhaseFiveMediaAccess(User $user): bool
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

    private function matchesContext(User $user, MediaUpload $upload): bool
    {
        return $this->tenantContext->isEstablished()
            && $this->tenantContext->membership()->user_id === $user->id
            && $this->tenantContext->familySpace()->id === $upload->family_space_id;
    }
}
