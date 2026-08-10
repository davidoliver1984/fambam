<?php

namespace App\Policies;

use App\Enums\FamilySpaceRole;
use App\Models\MediaUpload;
use App\Models\User;
use App\Tenancy\TenantContext;

class MediaUploadPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function create(User $user): bool
    {
        return $this->hasPhaseFiveUploadAccess($user);
    }

    public function complete(User $user, MediaUpload $upload): bool
    {
        return $this->hasPhaseFiveUploadAccess($user)
            && $this->tenantContext->familySpace()->id === $upload->family_space_id
            && $upload->user_id === $user->id;
    }

    private function hasPhaseFiveUploadAccess(User $user): bool
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
}
