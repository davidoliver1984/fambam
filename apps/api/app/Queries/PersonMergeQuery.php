<?php

namespace App\Queries;

use App\Enums\PersonMergeProposalStatus;
use App\Enums\PersonMergeStatus;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonMergeProposal;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PersonMergeQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Collection<int, PersonMergeProposal> */
    public function pendingProposals(Person $person): Collection
    {
        return PersonMergeProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('status', PersonMergeProposalStatus::Pending->value)
            ->where(function ($query) use ($person): void {
                $query->where('survivor_person_id', $person->id)
                    ->orWhere('absorbed_person_id', $person->id);
            })
            ->orderBy('created_at')
            ->get();
    }

    public function findProposal(string $proposalId): PersonMergeProposal
    {
        return PersonMergeProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($proposalId)
            ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, PersonMerge> */
    public function forPerson(Person $person): Collection
    {
        return PersonMerge::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where(function ($query) use ($person): void {
                $query->where('survivor_person_id', $person->id)
                    ->orWhere('absorbed_person_id', $person->id);
            })
            ->orderByDesc('merged_at')
            ->get();
    }

    public function find(string $mergeId): PersonMerge
    {
        return PersonMerge::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($mergeId)
            ?? throw new NotFoundHttpException;
    }

    public function redirectFor(string $absorbedPersonId): ?PersonMerge
    {
        return PersonMerge::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('absorbed_person_id', $absorbedPersonId)
            ->whereIn('status', [PersonMergeStatus::Active->value, PersonMergeStatus::ManualCorrectionRequired->value])
            ->latest('merged_at')
            ->first();
    }
}
