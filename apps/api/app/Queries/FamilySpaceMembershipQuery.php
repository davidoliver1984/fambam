<?php

namespace App\Queries;

use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FamilySpaceMembershipQuery
{
    /** @return Builder<FamilySpaceMembership> */
    public function forFamilySpace(FamilySpace $familySpace): Builder
    {
        return FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id);
    }

    public function activeForUser(FamilySpace $familySpace, User $user): FamilySpaceMembership
    {
        return $this->forFamilySpace($familySpace)
            ->where('user_id', $user->id)
            ->where('state', MembershipState::Active->value)
            ->first()
            ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, FamilySpaceMembership> */
    public function listForFamilySpace(FamilySpace $familySpace): Collection
    {
        return $this->forFamilySpace($familySpace)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();
    }

    public function findForFamilySpace(FamilySpace $familySpace, string $membershipId): FamilySpaceMembership
    {
        return $this->forFamilySpace($familySpace)->find($membershipId)
            ?? throw new NotFoundHttpException;
    }
}
