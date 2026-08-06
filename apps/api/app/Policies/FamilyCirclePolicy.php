<?php

namespace App\Policies;

use App\Enums\FamilySpaceRole;
use App\Models\FamilyCircle;
use App\Models\User;
use App\Tenancy\TenantContext;

class FamilyCirclePolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, FamilyCircle $circle): bool
    {
        return $this->canManage($user)
            && $circle->family_space_id === $this->tenantContext->familySpace()->id;
    }

    public function delete(User $user, FamilyCircle $circle): bool
    {
        return $this->update($user, $circle);
    }

    private function canManage(User $user): bool
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
