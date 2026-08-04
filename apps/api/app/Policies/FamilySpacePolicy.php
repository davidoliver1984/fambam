<?php

namespace App\Policies;

use App\Models\FamilySpace;
use App\Models\User;
use App\Tenancy\TenantContext;

class FamilySpacePolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function view(User $user, FamilySpace $familySpace): bool
    {
        return $this->matchesContext($user, $familySpace);
    }

    public function manageInvitations(User $user, FamilySpace $familySpace): bool
    {
        return $this->matchesContext($user, $familySpace)
            && $this->tenantContext->membership()->role->canManageMembers();
    }

    public function manageMembers(User $user, FamilySpace $familySpace): bool
    {
        return $this->matchesContext($user, $familySpace)
            && $this->tenantContext->membership()->role->canManageMembers();
    }

    private function matchesContext(User $user, FamilySpace $familySpace): bool
    {
        if (! $this->tenantContext->isEstablished()) {
            return false;
        }

        $membership = $this->tenantContext->membership();

        return $this->tenantContext->familySpace()->is($familySpace)
            && $membership->user_id === $user->id
            && $membership->family_space_id === $familySpace->id;
    }
}
