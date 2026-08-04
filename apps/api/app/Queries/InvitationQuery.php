<?php

namespace App\Queries;

use App\Models\FamilySpace;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvitationQuery
{
    /** @return Builder<Invitation> */
    public function forFamilySpace(FamilySpace $familySpace): Builder
    {
        return Invitation::query()->where('family_space_id', $familySpace->id);
    }

    /** @return Collection<int, Invitation> */
    public function listForFamilySpace(FamilySpace $familySpace): Collection
    {
        return $this->forFamilySpace($familySpace)->latest()->get();
    }

    public function findForFamilySpace(FamilySpace $familySpace, int $invitationId): Invitation
    {
        return $this->forFamilySpace($familySpace)->find($invitationId)
            ?? throw new NotFoundHttpException;
    }
}
