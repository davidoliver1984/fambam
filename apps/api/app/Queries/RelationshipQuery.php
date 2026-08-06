<?php

namespace App\Queries;

use App\Enums\RelationshipProposalStatus;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\RelationshipProposal;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RelationshipQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Collection<int, PersonRelationship> */
    public function forPerson(Person $person): Collection
    {
        return PersonRelationship::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where(function ($query) use ($person): void {
                $query->where('subject_person_id', $person->id)
                    ->orWhere('related_person_id', $person->id);
            })
            ->with(['subject:id,preferred_name', 'related:id,preferred_name'])
            ->orderBy('created_at')
            ->get();
    }

    public function find(string $relationshipId): PersonRelationship
    {
        return PersonRelationship::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($relationshipId)
            ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, RelationshipProposal> */
    public function pendingProposals(Person $person): Collection
    {
        return RelationshipProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('status', RelationshipProposalStatus::Pending->value)
            ->where(function ($query) use ($person): void {
                $query->where('subject_person_id', $person->id)
                    ->orWhere('related_person_id', $person->id);
            })
            ->orderBy('created_at')
            ->get();
    }

    public function findProposal(string $proposalId): RelationshipProposal
    {
        return RelationshipProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($proposalId)
            ?? throw new NotFoundHttpException;
    }
}
