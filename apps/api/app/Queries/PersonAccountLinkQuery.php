<?php

namespace App\Queries;

use App\Enums\MembershipState;
use App\Enums\PersonAccountClaimStatus;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountClaim;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PersonAccountLinkQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Collection<int, PersonAccountClaim> */
    public function pendingClaims(Person $person): Collection
    {
        return PersonAccountClaim::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('person_id', $person->id)
            ->where('status', PersonAccountClaimStatus::Pending->value)
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get();
    }

    public function findClaim(Person $person, string $claimId): PersonAccountClaim
    {
        return PersonAccountClaim::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('person_id', $person->id)
            ->find($claimId)
            ?? throw new NotFoundHttpException;
    }

    public function activeMembership(FamilySpace $familySpace, string $membershipId): FamilySpaceMembership
    {
        return FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id)
            ->where('state', MembershipState::Active->value)
            ->find($membershipId)
            ?? throw new NotFoundHttpException;
    }
}
