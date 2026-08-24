<?php

namespace App\Services;

use App\Enums\PersonMergeProposalStatus;
use App\Enums\PersonMergeStatus;
use App\Enums\RelationshipProposalStatus;
use App\Enums\RelationshipType;
use App\Models\FamilyCirclePerson;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonMerge;
use App\Models\PersonMergeProposal;
use App\Models\PersonRelationship;
use App\Models\Photo;
use App\Models\PhotoProvenanceProposal;
use App\Models\RelationshipProposal;
use App\Models\User;
use App\Relationships\RelationshipValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonMergeManager
{
    public function __construct(
        private readonly RelationshipValidator $relationshipValidator,
        private readonly AuditRecorder $audit,
    ) {}

    public function propose(
        Person $absorbed,
        Person $survivor,
        ?string $context,
        User $actor,
        Request $request,
    ): PersonMergeProposal {
        return DB::transaction(function () use ($absorbed, $survivor, $context, $actor, $request): PersonMergeProposal {
            [$lockedAbsorbed, $lockedSurvivor] = $this->lockPair($absorbed->id, $survivor->id);
            $this->validatePair($lockedAbsorbed, $lockedSurvivor);
            if (PersonMergeProposal::query()
                ->where('survivor_person_id', $lockedSurvivor->id)
                ->where('absorbed_person_id', $lockedAbsorbed->id)
                ->where('status', PersonMergeProposalStatus::Pending->value)
                ->exists()) {
                $this->fail('This duplicate merge has already been proposed.');
            }
            $proposal = PersonMergeProposal::query()->create([
                'family_space_id' => $lockedAbsorbed->family_space_id,
                'survivor_person_id' => $lockedSurvivor->id,
                'absorbed_person_id' => $lockedAbsorbed->id,
                'context' => $this->cleanContext($context),
                'status' => PersonMergeProposalStatus::Pending,
                'proposed_by' => $actor->id,
            ]);
            $this->audit->record('person.duplicate_proposed', $proposal, $actor, $request);

            return $proposal;
        });
    }

    public function merge(
        Person $absorbed,
        Person $survivor,
        ?string $accountLinkResolution,
        User $actor,
        Request $request,
    ): PersonMerge {
        return DB::transaction(function () use ($absorbed, $survivor, $accountLinkResolution, $actor, $request): PersonMerge {
            [$lockedAbsorbed, $lockedSurvivor] = $this->lockPair($absorbed->id, $survivor->id);
            $this->validatePair($lockedAbsorbed, $lockedSurvivor);
            $before = $this->captureState($lockedAbsorbed->id, $lockedSurvivor->id, true);

            $relationshipIdMap = $this->reconcileRelationships($lockedAbsorbed, $lockedSurvivor);
            $this->reconcileRelationshipProposals(
                $lockedAbsorbed,
                $lockedSurvivor,
                $relationshipIdMap,
                $actor,
            );
            $this->reconcileCircles($lockedAbsorbed, $lockedSurvivor);
            $this->reconcileAccountLinks(
                $lockedAbsorbed,
                $lockedSurvivor,
                $accountLinkResolution,
            );
            $this->reconcilePhotoProvenance($lockedAbsorbed, $lockedSurvivor);
            $lockedAbsorbed->delete();

            $merge = PersonMerge::query()->create([
                'family_space_id' => $lockedSurvivor->family_space_id,
                'survivor_person_id' => $lockedSurvivor->id,
                'absorbed_person_id' => $lockedAbsorbed->id,
                'status' => PersonMergeStatus::Active,
                'provenance' => [
                    'schema_version' => 1,
                    'before' => $before,
                    'after' => $this->captureState($lockedAbsorbed->id, $lockedSurvivor->id),
                ],
                'merged_by' => $actor->id,
                'merged_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record('person.merged', $merge, $actor, $request, [
                'survivor_person_id' => $lockedSurvivor->id,
                'absorbed_person_id' => $lockedAbsorbed->id,
            ]);

            return $merge;
        });
    }

    public function resolveProposal(
        PersonMergeProposal $proposal,
        PersonMergeProposalStatus $resolution,
        ?string $accountLinkResolution,
        User $actor,
        Request $request,
    ): PersonMergeProposal {
        if ($resolution === PersonMergeProposalStatus::Pending) {
            $this->fail('A duplicate proposal must be approved or rejected.');
        }

        return DB::transaction(function () use ($proposal, $resolution, $accountLinkResolution, $actor, $request): PersonMergeProposal {
            $locked = PersonMergeProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->status !== PersonMergeProposalStatus::Pending) {
                $this->fail('This duplicate proposal can no longer be resolved.');
            }

            $merge = null;
            if ($resolution === PersonMergeProposalStatus::Approved) {
                $absorbed = Person::query()->findOrFail($locked->absorbed_person_id);
                $survivor = Person::query()->findOrFail($locked->survivor_person_id);
                $merge = $this->merge($absorbed, $survivor, $accountLinkResolution, $actor, $request);
            }
            $locked->update([
                'status' => $resolution,
                'person_merge_id' => $merge?->id,
                'resolved_by' => $actor->id,
                'resolved_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record(
                $resolution === PersonMergeProposalStatus::Approved
                    ? 'person.duplicate_proposal_approved'
                    : 'person.duplicate_proposal_rejected',
                $locked,
                $actor,
                $request,
            );

            return $locked;
        });
    }

    public function reverse(PersonMerge $merge, User $actor, Request $request): PersonMerge
    {
        $result = DB::transaction(function () use ($merge, $actor, $request): ?PersonMerge {
            $locked = PersonMerge::query()->lockForUpdate()->findOrFail($merge->id);
            if (! in_array($locked->status, [PersonMergeStatus::Active, PersonMergeStatus::ManualCorrectionRequired], true)) {
                $this->fail('This Person merge can no longer be reversed.');
            }

            [$absorbed, $survivor] = $this->lockPairWithTrashed(
                $locked->absorbed_person_id,
                $locked->survivor_person_id,
            );
            /** @var array<string, mixed> $provenance */
            $provenance = $locked->provenance;
            /** @var array<string, mixed> $after */
            $after = $provenance['after'] ?? [];
            /** @var array<string, mixed> $before */
            $before = $provenance['before'] ?? [];
            if ($absorbed->deleted_at === null
                || ! $this->statesMatch($after, $this->captureState($absorbed->id, $survivor->id, true))
                || ! $this->linksCanBeRestored($before, [$absorbed->id, $survivor->id])) {
                $locked->update([
                    'status' => PersonMergeStatus::ManualCorrectionRequired,
                    'manual_correction_required_at' => CarbonImmutable::now(),
                ]);
                $this->audit->record('person.merge_manual_correction_required', $locked, $actor, $request);

                return null;
            }

            $this->restoreState($before, [$absorbed->id, $survivor->id]);
            $absorbed->restore();
            $locked->update([
                'status' => PersonMergeStatus::Reversed,
                'reversed_by' => $actor->id,
                'reversed_at' => CarbonImmutable::now(),
                'manual_correction_required_at' => null,
            ]);
            $this->audit->record('person.merge_reversed', $locked, $actor, $request);

            return $locked;
        });

        if ($result === null) {
            $this->fail('Automatic reversal is unsafe because the merged Person state changed; manual correction is required.');
        }

        return $result;
    }

    /** @return array{Person, Person} */
    private function lockPair(string $absorbedId, string $survivorId): array
    {
        $people = Person::query()
            ->whereIn('id', [$absorbedId, $survivorId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $absorbed = $people->get($absorbedId);
        $survivor = $people->get($survivorId);
        if (! $absorbed instanceof Person || ! $survivor instanceof Person) {
            $this->fail('Both active People must be available in this Family Space.');
        }

        return [$absorbed, $survivor];
    }

    /** @return array{Person, Person} */
    private function lockPairWithTrashed(string $absorbedId, string $survivorId): array
    {
        $people = Person::withTrashed()
            ->whereIn('id', [$absorbedId, $survivorId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $absorbed = $people->get($absorbedId);
        $survivor = $people->get($survivorId);
        if (! $absorbed instanceof Person || ! $survivor instanceof Person) {
            $this->fail('The merge People are no longer available.');
        }

        return [$absorbed, $survivor];
    }

    private function validatePair(Person $absorbed, Person $survivor): void
    {
        if ($absorbed->id === $survivor->id) {
            $this->fail('A Person cannot be merged into themselves.');
        }
        if ($absorbed->family_space_id !== $survivor->family_space_id) {
            $this->fail('Both People must belong to the same Family Space.');
        }
        if (PersonMerge::query()
            ->where('absorbed_person_id', $absorbed->id)
            ->whereIn('status', [PersonMergeStatus::Active->value, PersonMergeStatus::ManualCorrectionRequired->value])
            ->exists()) {
            $this->fail('This Person has already been merged.');
        }
        if (PersonMerge::query()
            ->where('survivor_person_id', $absorbed->id)
            ->whereIn('status', [PersonMergeStatus::Active->value, PersonMergeStatus::ManualCorrectionRequired->value])
            ->exists()) {
            $this->fail('Reverse this Person’s existing merges before absorbing them into another Person.');
        }
    }

    /** @return array<string, string|null> */
    private function reconcileRelationships(Person $absorbed, Person $survivor): array
    {
        $relationships = PersonRelationship::query()
            ->where(function ($query) use ($absorbed, $survivor): void {
                $query->whereIn('subject_person_id', [$absorbed->id, $survivor->id])
                    ->orWhereIn('related_person_id', [$absorbed->id, $survivor->id]);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $thirdPartyIds = $relationships
            ->flatMap(fn (PersonRelationship $relationship): array => [
                $relationship->subject_person_id,
                $relationship->related_person_id,
            ])
            ->reject(fn (string $personId): bool => in_array($personId, [$absorbed->id, $survivor->id], true))
            ->unique()
            ->sort()
            ->values();
        $thirdParties = Person::query()
            ->whereIn('id', $thirdPartyIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $idMap = [];

        foreach ($relationships as $relationship) {
            if ($relationship->subject_person_id !== $absorbed->id
                && $relationship->related_person_id !== $absorbed->id) {
                continue;
            }
            $subjectId = $relationship->subject_person_id === $absorbed->id
                ? $survivor->id
                : $relationship->subject_person_id;
            $relatedId = $relationship->related_person_id === $absorbed->id
                ? $survivor->id
                : $relationship->related_person_id;
            if ($subjectId === $relatedId) {
                $idMap[$relationship->id] = null;
                $relationship->delete();

                continue;
            }

            $ids = $this->canonicalRelationshipIds($subjectId, $relatedId, $relationship->type);
            $duplicate = PersonRelationship::query()
                ->whereKeyNot($relationship->id)
                ->where('type', $relationship->type->value)
                ->where('subject_person_id', $ids['subject_person_id'])
                ->where('related_person_id', $ids['related_person_id'])
                ->lockForUpdate()
                ->first();
            if ($duplicate !== null) {
                $idMap[$relationship->id] = $duplicate->id;
                RelationshipProposal::query()
                    ->where('relationship_id', $relationship->id)
                    ->update(['relationship_id' => $duplicate->id]);
                $relationship->delete();

                continue;
            }
            $subject = $ids['subject_person_id'] === $survivor->id
                ? $survivor
                : $thirdParties->get($ids['subject_person_id']);
            $related = $ids['related_person_id'] === $survivor->id
                ? $survivor
                : $thirdParties->get($ids['related_person_id']);
            if (! $subject instanceof Person || ! $related instanceof Person) {
                $this->fail('Every related Person must remain available during the merge.');
            }
            $ids = $this->relationshipValidator->validate(
                $subject,
                $related,
                $relationship->type,
                $relationship->id,
            );
            $relationship->update($ids);
            $idMap[$relationship->id] = $relationship->id;
        }

        return $idMap;
    }

    /** @param array<string, string|null> $relationshipIdMap */
    private function reconcileRelationshipProposals(
        Person $absorbed,
        Person $survivor,
        array $relationshipIdMap,
        User $actor,
    ): void {
        $relationshipIds = array_keys($relationshipIdMap);
        $proposals = RelationshipProposal::query()
            ->where(function ($query) use ($absorbed, $survivor, $relationshipIds): void {
                $query->whereIn('subject_person_id', [$absorbed->id, $survivor->id])
                    ->orWhereIn('related_person_id', [$absorbed->id, $survivor->id]);
                if ($relationshipIds !== []) {
                    $query->orWhereIn('relationship_id', $relationshipIds);
                }
            })
            ->lockForUpdate()
            ->get();

        foreach ($proposals as $proposal) {
            $subjectId = $proposal->subject_person_id === $absorbed->id
                ? $survivor->id
                : $proposal->subject_person_id;
            $relatedId = $proposal->related_person_id === $absorbed->id
                ? $survivor->id
                : $proposal->related_person_id;
            $relationshipId = $proposal->relationship_id;
            if ($relationshipId !== null && array_key_exists($relationshipId, $relationshipIdMap)) {
                $relationshipId = $relationshipIdMap[$relationshipId];
            }
            $attributes = [
                'subject_person_id' => $subjectId,
                'related_person_id' => $relatedId,
                'relationship_id' => $relationshipId,
            ];
            if ($subjectId === $relatedId && $proposal->status === RelationshipProposalStatus::Pending) {
                $attributes = [
                    ...$attributes,
                    'status' => RelationshipProposalStatus::Rejected,
                    'resolved_by' => $actor->id,
                    'resolved_at' => CarbonImmutable::now(),
                ];
            }
            $proposal->update($attributes);
        }
    }

    private function reconcileCircles(Person $absorbed, Person $survivor): void
    {
        $memberships = FamilyCirclePerson::query()
            ->whereIn('person_id', [$absorbed->id, $survivor->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($memberships->where('person_id', $absorbed->id) as $membership) {
            $duplicate = $memberships->first(
                fn (FamilyCirclePerson $candidate): bool => $candidate->person_id === $survivor->id
                    && $candidate->family_circle_id === $membership->family_circle_id,
            );
            if ($duplicate !== null) {
                $membership->delete();
            } else {
                $membership->update(['person_id' => $survivor->id]);
            }
        }
    }

    private function reconcileAccountLinks(
        Person $absorbed,
        Person $survivor,
        ?string $resolution,
    ): void {
        $links = PersonAccountLink::query()
            ->whereIn('person_id', [$absorbed->id, $survivor->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $absorbedLink = $links->firstWhere('person_id', $absorbed->id);
        $survivorLink = $links->firstWhere('person_id', $survivor->id);
        if ($absorbedLink === null) {
            return;
        }
        if ($survivorLink === null) {
            $absorbedLink->update(['person_id' => $survivor->id]);

            return;
        }
        if (! in_array($resolution, ['keep_survivor', 'keep_absorbed', 'remove_both'], true)) {
            $this->fail('Choose which account link to retain before merging these People.');
        }
        if ($resolution === 'keep_survivor') {
            $absorbedLink->delete();
        } elseif ($resolution === 'keep_absorbed') {
            $survivorLink->delete();
            $absorbedLink->update(['person_id' => $survivor->id]);
        } else {
            $absorbedLink->delete();
            $survivorLink->delete();
        }
    }

    private function reconcilePhotoProvenance(Person $absorbed, Person $survivor): void
    {
        Photo::query()
            ->where('photographer_person_id', $absorbed->id)
            ->update(['photographer_person_id' => $survivor->id, 'updated_at' => now()]);
        Photo::query()
            ->where('scanner_person_id', $absorbed->id)
            ->update(['scanner_person_id' => $survivor->id, 'updated_at' => now()]);
        Photo::query()
            ->where('physical_owner_person_id', $absorbed->id)
            ->update(['physical_owner_person_id' => $survivor->id, 'updated_at' => now()]);
        PhotoProvenanceProposal::query()
            ->where('person_id', $absorbed->id)
            ->update(['person_id' => $survivor->id, 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function captureState(string $absorbedId, string $survivorId, bool $lock = false): array
    {
        $personIds = [$absorbedId, $survivorId];
        $relationshipsQuery = PersonRelationship::query()
            ->where(function ($query) use ($personIds): void {
                $query->whereIn('subject_person_id', $personIds)
                    ->orWhereIn('related_person_id', $personIds);
            })
            ->orderBy('id');
        if ($lock) {
            $relationshipsQuery->lockForUpdate();
        }
        $relationships = $relationshipsQuery->get();
        $relationshipIds = $relationships->pluck('id')->all();
        $proposalQuery = RelationshipProposal::query()
            ->where(function ($query) use ($personIds, $relationshipIds): void {
                $query->whereIn('subject_person_id', $personIds)
                    ->orWhereIn('related_person_id', $personIds);
                if ($relationshipIds !== []) {
                    $query->orWhereIn('relationship_id', $relationshipIds);
                }
            })
            ->orderBy('id');
        $circleQuery = FamilyCirclePerson::query()->whereIn('person_id', $personIds)->orderBy('id');
        $linkQuery = PersonAccountLink::query()->whereIn('person_id', $personIds)->orderBy('id');
        $photoQuery = Photo::query()
            ->where(function ($query) use ($personIds): void {
                $query->whereIn('photographer_person_id', $personIds)
                    ->orWhereIn('scanner_person_id', $personIds)
                    ->orWhereIn('physical_owner_person_id', $personIds);
            })
            ->orderBy('id');
        $photoProposalQuery = PhotoProvenanceProposal::query()
            ->whereIn('person_id', $personIds)
            ->orderBy('id');
        if ($lock) {
            $proposalQuery->lockForUpdate();
            $circleQuery->lockForUpdate();
            $linkQuery->lockForUpdate();
            $photoQuery->lockForUpdate();
            $photoProposalQuery->lockForUpdate();
        }

        return [
            'relationships' => $relationships->map($this->relationshipSnapshot(...))->values()->all(),
            'relationship_proposals' => $proposalQuery->get()->map($this->proposalSnapshot(...))->values()->all(),
            'circle_memberships' => $circleQuery->get()->map($this->circleSnapshot(...))->values()->all(),
            'account_links' => $linkQuery->get()->map($this->linkSnapshot(...))->values()->all(),
            'photo_provenance' => $photoQuery->get()->map($this->photoProvenanceSnapshot(...))->values()->all(),
            'photo_provenance_proposals' => $photoProposalQuery->get()
                ->map($this->photoProvenanceProposalSnapshot(...))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function relationshipSnapshot(PersonRelationship $relationship): array
    {
        return [
            'id' => $relationship->id,
            'family_space_id' => $relationship->family_space_id,
            'subject_person_id' => $relationship->subject_person_id,
            'related_person_id' => $relationship->related_person_id,
            'type' => $relationship->type->value,
            'status' => $relationship->status->value,
            'context' => $relationship->context,
            'created_by' => $relationship->created_by,
            'updated_by' => $relationship->updated_by,
            'created_at' => $relationship->getRawOriginal('created_at'),
            'updated_at' => $relationship->getRawOriginal('updated_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function proposalSnapshot(RelationshipProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'relationship_id' => $proposal->relationship_id,
            'subject_person_id' => $proposal->subject_person_id,
            'related_person_id' => $proposal->related_person_id,
            'status' => $proposal->status->value,
            'resolved_by' => $proposal->resolved_by,
            'resolved_at' => $proposal->getRawOriginal('resolved_at'),
            'updated_at' => $proposal->getRawOriginal('updated_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function circleSnapshot(FamilyCirclePerson $membership): array
    {
        return [
            'id' => $membership->id,
            'family_space_id' => $membership->family_space_id,
            'family_circle_id' => $membership->family_circle_id,
            'person_id' => $membership->person_id,
            'added_by' => $membership->added_by,
            'created_at' => $membership->getRawOriginal('created_at'),
            'updated_at' => $membership->getRawOriginal('updated_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function linkSnapshot(PersonAccountLink $link): array
    {
        return [
            'id' => $link->id,
            'family_space_id' => $link->family_space_id,
            'person_id' => $link->person_id,
            'user_id' => $link->user_id,
            'created_by' => $link->created_by,
            'created_at' => $link->getRawOriginal('created_at'),
            'updated_at' => $link->getRawOriginal('updated_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function photoProvenanceSnapshot(Photo $photo): array
    {
        return [
            'id' => $photo->id,
            'photographer_person_id' => $photo->photographer_person_id,
            'scanner_person_id' => $photo->scanner_person_id,
            'physical_owner_person_id' => $photo->physical_owner_person_id,
            'updated_at' => $photo->getRawOriginal('updated_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function photoProvenanceProposalSnapshot(PhotoProvenanceProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'person_id' => $proposal->person_id,
            'updated_at' => $proposal->getRawOriginal('updated_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function statesMatch(array $expected, array $actual): bool
    {
        return $expected === $actual;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  list<string>  $personIds
     */
    private function linksCanBeRestored(array $before, array $personIds): bool
    {
        /** @var list<array<string, mixed>> $links */
        $links = $before['account_links'] ?? [];
        foreach ($links as $link) {
            if (PersonAccountLink::query()
                ->where('family_space_id', $link['family_space_id'])
                ->where('user_id', $link['user_id'])
                ->whereNotIn('person_id', $personIds)
                ->exists()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  list<string>  $personIds
     */
    private function restoreState(array $before, array $personIds): void
    {
        /** @var list<array<string, mixed>> $relationships */
        $relationships = $before['relationships'] ?? [];
        $beforeRelationshipIds = array_column($relationships, 'id');
        PersonRelationship::query()
            ->where(function ($query) use ($personIds): void {
                $query->whereIn('subject_person_id', $personIds)
                    ->orWhereIn('related_person_id', $personIds);
            })
            ->whereNotIn('id', $beforeRelationshipIds)
            ->delete();
        foreach ($relationships as $row) {
            DB::table('person_relationships')->updateOrInsert(['id' => $row['id']], $row);
        }

        /** @var list<array<string, mixed>> $proposals */
        $proposals = $before['relationship_proposals'] ?? [];
        foreach ($proposals as $row) {
            $id = $row['id'];
            unset($row['id']);
            DB::table('relationship_proposals')->where('id', $id)->update($row);
        }

        FamilyCirclePerson::query()->whereIn('person_id', $personIds)->delete();
        /** @var list<array<string, mixed>> $memberships */
        $memberships = $before['circle_memberships'] ?? [];
        foreach ($memberships as $row) {
            DB::table('family_circle_people')->insert($row);
        }

        PersonAccountLink::query()->whereIn('person_id', $personIds)->delete();
        /** @var list<array<string, mixed>> $links */
        $links = $before['account_links'] ?? [];
        foreach ($links as $row) {
            DB::table('person_account_links')->insert($row);
        }

        /** @var list<array<string, mixed>> $photoProvenance */
        $photoProvenance = $before['photo_provenance'] ?? [];
        foreach ($photoProvenance as $row) {
            $id = $row['id'];
            unset($row['id']);
            DB::table('photos')->where('id', $id)->update($row);
        }

        /** @var list<array<string, mixed>> $photoProposals */
        $photoProposals = $before['photo_provenance_proposals'] ?? [];
        foreach ($photoProposals as $row) {
            $id = $row['id'];
            unset($row['id']);
            DB::table('photo_provenance_proposals')->where('id', $id)->update($row);
        }
    }

    private function cleanContext(?string $context): ?string
    {
        return $context !== null && trim($context) !== '' ? trim($context) : null;
    }

    /** @return array{subject_person_id: string, related_person_id: string} */
    private function canonicalRelationshipIds(
        string $subjectId,
        string $relatedId,
        RelationshipType $type,
    ): array {
        if ($type->isSymmetric() && strcmp($subjectId, $relatedId) > 0) {
            return ['subject_person_id' => $relatedId, 'related_person_id' => $subjectId];
        }

        return ['subject_person_id' => $subjectId, 'related_person_id' => $relatedId];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['person_merge' => [$message]]);
    }
}
