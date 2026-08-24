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
        if (! $this->matchesContext($user, $upload) || $upload->user_id !== $user->id) {
            return false;
        }
        if ($upload->target_album_id === null) {
            return $this->hasPhaseFiveMediaAccess($user);
        }
        $upload->loadMissing('targetAlbum');

        return $upload->targetAlbum !== null && $user->can('contribute', $upload->targetAlbum);
    }

    public function view(User $user, MediaUpload $upload): bool
    {
        if (! $this->matchesContext($user, $upload)) {
            return false;
        }

        $upload->loadMissing('photo');
        if ($upload->photo === null) {
            if ($this->hasPhaseFiveMediaAccess($user)) {
                return true;
            }
            $upload->loadMissing('targetAlbum');

            return $upload->user_id === $user->id
                && $upload->targetAlbum !== null
                && $user->can('contribute', $upload->targetAlbum);
        }

        return $user->can('view', $upload->photo);
    }

    public function downloadOriginal(User $user, MediaUpload $upload): bool
    {
        if (! $this->hasPhaseFiveMediaAccess($user) || ! $this->matchesContext($user, $upload)) {
            return false;
        }
        $upload->loadMissing('photo');
        if ($upload->photo?->trashed() === true) {
            return false;
        }

        return $upload->photo === null || $this->tenantContext->membership()->role->canManageMembers()
            || ($upload->photo->visibility === PhotoVisibility::FamilySpace
                && $this->tenantContext->membership()->role === FamilySpaceRole::Member)
            || $upload->photo->created_by === $user->id;
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
