<?php

namespace App\Tenancy;

use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use LogicException;

class TenantContext
{
    private ?FamilySpace $familySpace = null;

    private ?FamilySpaceMembership $membership = null;

    public function establish(
        FamilySpace $familySpace,
        FamilySpaceMembership $membership,
        User $user,
    ): void {
        if ($membership->family_space_id !== $familySpace->id
            || $membership->user_id !== $user->id
            || $membership->state !== MembershipState::Active) {
            throw new LogicException('Tenant context requires the user\'s active membership.');
        }

        $this->familySpace = $familySpace;
        $this->membership = $membership;
    }

    public function familySpace(): FamilySpace
    {
        return $this->familySpace
            ?? throw new LogicException('Family Space context has not been established.');
    }

    public function membership(): FamilySpaceMembership
    {
        return $this->membership
            ?? throw new LogicException('Family Space context has not been established.');
    }

    public function isEstablished(): bool
    {
        return $this->familySpace !== null && $this->membership !== null;
    }

    public function clear(): void
    {
        $this->familySpace = null;
        $this->membership = null;
    }
}
