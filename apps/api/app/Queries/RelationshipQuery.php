<?php

namespace App\Queries;

use App\Enums\RelationshipProposalStatus;
use App\Enums\RelationshipStatus;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonRelationship;
use App\Models\RelationshipProposal;
use App\Models\User;
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

    /** @return Collection<int, PersonRelationship> */
    public function confirmedForPerson(Person $person): Collection
    {
        return PersonRelationship::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('status', RelationshipStatus::Confirmed->value)
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

    /**
     * Returns the target Person's direct, confirmed relationship to the viewer.
     *
     * @param  list<string>  $personIds
     * @return array<string, array{type: string, label: string}>
     */
    public function summariesToViewer(array $personIds, User $viewer): array
    {
        $personIds = array_values(array_unique(array_filter($personIds)));
        if ($personIds === []) {
            return [];
        }
        $familySpaceId = $this->tenantContext->familySpace()->id;
        $viewerPersonId = PersonAccountLink::query()
            ->where('family_space_id', $familySpaceId)
            ->where('user_id', $viewer->id)
            ->value('person_id');
        if (! is_string($viewerPersonId)) {
            return [];
        }

        return PersonRelationship::query()
            ->where('family_space_id', $familySpaceId)
            ->where('status', RelationshipStatus::Confirmed->value)
            ->where(function ($query) use ($viewerPersonId, $personIds): void {
                $query->where(function ($forward) use ($viewerPersonId, $personIds): void {
                    $forward->where('subject_person_id', $viewerPersonId)
                        ->whereIn('related_person_id', $personIds);
                })->orWhere(function ($inverse) use ($viewerPersonId, $personIds): void {
                    $inverse->where('related_person_id', $viewerPersonId)
                        ->whereIn('subject_person_id', $personIds);
                });
            })
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (PersonRelationship $relationship) use ($viewerPersonId): array {
                $targetIsSubject = $relationship->related_person_id === $viewerPersonId;
                $targetId = $targetIsSubject
                    ? $relationship->subject_person_id
                    : $relationship->related_person_id;

                return [$targetId => [
                    'type' => $relationship->type->value,
                    'label' => $targetIsSubject
                        ? $relationship->type->forwardLabel()
                        : $relationship->type->inverseLabel(),
                ]];
            })->all();
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
