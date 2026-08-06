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
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PersonMergeQuery $merges,
    ) {}

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
        $person = Person::withTrashed()
            ->with('accountLink.user:id,name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($personId)
            ?? throw new NotFoundHttpException;
        if ($person->deleted_at === null) {
            return $person;
        }

        $merge = $this->merges->redirectFor($person->id)
            ?? throw new NotFoundHttpException;
        $survivor = $this->forCurrentFamilySpace()->find($merge->survivor_person_id)
            ?? throw new NotFoundHttpException;
        $survivor->setAttribute('redirected_from_person_id', $person->id);

        return $survivor;
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
