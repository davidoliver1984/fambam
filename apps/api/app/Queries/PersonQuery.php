<?php

namespace App\Queries;

use App\Enums\PersonProposalStatus;
use App\Models\Person;
use App\Models\PersonDetailProposal;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PersonQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Builder<Person> */
    public function forCurrentFamilySpace(): Builder
    {
        return Person::query()
            ->with('accountLink.user:id,name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id);
    }

    /** @return Collection<int, Person> */
    public function listForCurrentFamilySpace(): Collection
    {
        return $this->forCurrentFamilySpace()
            ->orderBy('preferred_name')
            ->orderBy('id')
            ->get();
    }

    public function findForCurrentFamilySpace(string $personId): Person
    {
        return $this->forCurrentFamilySpace()->find($personId)
            ?? throw new NotFoundHttpException;
    }

    public function findProposal(Person $person, string $proposalId): PersonDetailProposal
    {
        return PersonDetailProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('person_id', $person->id)
            ->find($proposalId)
            ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, PersonDetailProposal> */
    public function pendingProposals(Person $person): Collection
    {
        return PersonDetailProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('person_id', $person->id)
            ->where('status', PersonProposalStatus::Pending->value)
            ->orderBy('created_at')
            ->get();
    }
}
