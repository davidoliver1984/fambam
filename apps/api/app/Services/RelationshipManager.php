<?php

namespace App\Services;

use App\Enums\RelationshipProposalAction;
use App\Enums\RelationshipProposalStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipType;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\RelationshipProposal;
use App\Models\User;
use App\Relationships\RelationshipValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RelationshipManager
{
    public function __construct(
        private readonly RelationshipValidator $validator,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(
        Person $subject,
        Person $related,
        RelationshipType $type,
        ?string $context,
        User $actor,
        Request $request,
    ): PersonRelationship {
        return DB::transaction(function () use ($subject, $related, $type, $context, $actor, $request): PersonRelationship {
            [$lockedSubject, $lockedRelated] = $this->lockPeople($subject->id, $related->id);
            $ids = $this->validator->validate($lockedSubject, $lockedRelated, $type);
            $relationship = PersonRelationship::query()->create([
                'family_space_id' => $lockedSubject->family_space_id,
                ...$ids,
                'type' => $type,
                'status' => RelationshipStatus::Confirmed,
                'context' => $this->cleanContext($context),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->audit->record('person.relationship_created', $relationship, $actor, $request);

            return $relationship;
        });
    }

    public function replace(
        PersonRelationship $relationship,
        Person $subject,
        Person $related,
        RelationshipType $type,
        ?string $context,
        User $actor,
        Request $request,
    ): PersonRelationship {
        return DB::transaction(function () use ($relationship, $subject, $related, $type, $context, $actor, $request): PersonRelationship {
            $locked = PersonRelationship::query()->lockForUpdate()->findOrFail($relationship->id);
            [$lockedSubject, $lockedRelated] = $this->lockPeople($subject->id, $related->id);
            $ids = $this->validator->validate($lockedSubject, $lockedRelated, $type, $locked->id);
            $before = [
                'subject_person_id' => $locked->subject_person_id,
                'related_person_id' => $locked->related_person_id,
                'type' => $locked->type->value,
            ];
            $locked->update([
                ...$ids,
                'type' => $type,
                'status' => RelationshipStatus::Confirmed,
                'context' => $this->cleanContext($context),
                'updated_by' => $actor->id,
            ]);
            $this->audit->record('person.relationship_replaced', $locked, $actor, $request, ['previous' => $before]);

            return $locked;
        });
    }

    public function remove(PersonRelationship $relationship, User $actor, Request $request): void
    {
        DB::transaction(function () use ($relationship, $actor, $request): void {
            $locked = PersonRelationship::query()->lockForUpdate()->findOrFail($relationship->id);
            $this->audit->record('person.relationship_removed', $locked, $actor, $request, [
                'subject_person_id' => $locked->subject_person_id,
                'related_person_id' => $locked->related_person_id,
                'type' => $locked->type->value,
            ]);
            $locked->delete();
        });
    }

    public function dispute(PersonRelationship $relationship, User $actor, Request $request): PersonRelationship
    {
        return DB::transaction(function () use ($relationship, $actor, $request): PersonRelationship {
            $locked = PersonRelationship::query()->lockForUpdate()->findOrFail($relationship->id);
            $locked->update(['status' => RelationshipStatus::Disputed, 'updated_by' => $actor->id]);
            $this->audit->record('person.relationship_disputed', $locked, $actor, $request);

            return $locked;
        });
    }

    /** @param array<string, mixed> $input */
    public function propose(array $input, User $actor, Request $request): RelationshipProposal
    {
        return DB::transaction(function () use ($input, $actor, $request): RelationshipProposal {
            $action = RelationshipProposalAction::from((string) $input['action']);
            $relationship = $action !== RelationshipProposalAction::Create && isset($input['relationship_id'])
                ? PersonRelationship::query()->lockForUpdate()->findOrFail((string) $input['relationship_id'])
                : null;
            $subject = null;
            $related = null;
            $type = null;

            if (in_array($action, [RelationshipProposalAction::Create, RelationshipProposalAction::Replace], true)) {
                [$subject, $related] = $this->lockPeople(
                    (string) $input['subject_person_id'],
                    (string) $input['related_person_id'],
                );
                $type = RelationshipType::from((string) $input['type']);
                $ids = $this->validator->validate($subject, $related, $type, $relationship?->id);
                $familySpaceId = $subject->family_space_id;
            } else {
                if ($relationship === null) {
                    $this->fail('The proposed action requires an existing relationship.');
                }
                $ids = [
                    'subject_person_id' => $relationship->subject_person_id,
                    'related_person_id' => $relationship->related_person_id,
                ];
                $type = $relationship->type;
                $familySpaceId = $relationship->family_space_id;
            }

            $proposal = RelationshipProposal::query()->create([
                'family_space_id' => $familySpaceId,
                'action' => $action,
                'relationship_id' => $relationship?->id,
                ...$ids,
                'type' => $type,
                'context' => $this->cleanContext($input['context'] ?? null),
                'relationship_snapshot' => $relationship !== null
                    ? $this->relationshipState($relationship)
                    : null,
                'status' => RelationshipProposalStatus::Pending,
                'proposed_by' => $actor->id,
            ]);
            $this->audit->record('person.relationship_proposed', $proposal, $actor, $request, [
                'action' => $action->value,
            ]);

            return $proposal;
        });
    }

    public function resolve(
        RelationshipProposal $proposal,
        RelationshipProposalStatus $resolution,
        User $actor,
        Request $request,
    ): RelationshipProposal {
        if ($resolution === RelationshipProposalStatus::Pending) {
            $this->fail('A proposal must be approved or rejected.');
        }

        return DB::transaction(function () use ($proposal, $resolution, $actor, $request): RelationshipProposal {
            $locked = RelationshipProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->status !== RelationshipProposalStatus::Pending) {
                $this->fail('This relationship proposal can no longer be resolved.');
            }

            if ($resolution === RelationshipProposalStatus::Approved) {
                $this->applyApprovedProposal($locked, $actor, $request);
            }
            $locked->update([
                'status' => $resolution,
                'resolved_by' => $actor->id,
                'resolved_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record(
                $resolution === RelationshipProposalStatus::Approved
                    ? 'person.relationship_proposal_approved'
                    : 'person.relationship_proposal_rejected',
                $locked,
                $actor,
                $request,
                ['action' => $locked->action->value],
            );

            return $locked;
        });
    }

    private function applyApprovedProposal(RelationshipProposal $proposal, User $actor, Request $request): void
    {
        if ($proposal->action === RelationshipProposalAction::Create) {
            if ($proposal->subject_person_id === null
                || $proposal->related_person_id === null
                || $proposal->type === null) {
                $this->fail('The proposed relationship is incomplete.');
            }
            [$subject, $related] = $this->lockPeople($proposal->subject_person_id, $proposal->related_person_id);
            $this->create($subject, $related, $proposal->type, $proposal->context, $actor, $request);

            return;
        }

        $relationship = PersonRelationship::query()->lockForUpdate()->find($proposal->relationship_id);
        if ($relationship === null) {
            $this->fail('The relationship changed before this proposal could be approved.');
        }
        if ($proposal->relationship_snapshot === null
            || $proposal->relationship_snapshot !== $this->relationshipState($relationship)) {
            $this->fail('The relationship changed before this proposal could be approved.');
        }

        if ($proposal->action === RelationshipProposalAction::Replace) {
            if ($proposal->subject_person_id === null
                || $proposal->related_person_id === null
                || $proposal->type === null) {
                $this->fail('The proposed replacement is incomplete.');
            }
            [$subject, $related] = $this->lockPeople($proposal->subject_person_id, $proposal->related_person_id);
            $this->replace(
                $relationship,
                $subject,
                $related,
                $proposal->type,
                $proposal->context,
                $actor,
                $request,
            );

            return;
        }

        if ($proposal->action === RelationshipProposalAction::Remove) {
            $this->remove($relationship, $actor, $request);

            return;
        }

        $this->dispute($relationship, $actor, $request);
    }

    /** @return array{Person, Person} */
    private function lockPeople(string $subjectId, string $relatedId): array
    {
        $people = Person::query()
            ->whereIn('id', [$subjectId, $relatedId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $subject = $people->get($subjectId);
        $related = $people->get($relatedId);
        if (! $subject instanceof Person || ! $related instanceof Person) {
            $this->fail('Both People must be available in this Family Space.');
        }

        return [$subject, $related];
    }

    /** @return array<string, mixed> */
    private function relationshipState(PersonRelationship $relationship): array
    {
        return [
            'subject_person_id' => $relationship->subject_person_id,
            'related_person_id' => $relationship->related_person_id,
            'type' => $relationship->type->value,
            'status' => $relationship->status->value,
            'context' => $relationship->context,
        ];
    }

    private function cleanContext(mixed $context): ?string
    {
        return is_string($context) && trim($context) !== '' ? trim($context) : null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['relationship' => [$message]]);
    }
}
