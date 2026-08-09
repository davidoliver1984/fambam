<?php

namespace App\Relationships;

use App\Enums\RelationshipType;
use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Validation\ValidationException;

class RelationshipValidator
{
    /** @return array{subject_person_id: string, related_person_id: string} */
    public function validate(
        Person $subject,
        Person $related,
        RelationshipType $type,
        ?string $ignoreRelationshipId = null,
    ): array {
        if ($subject->family_space_id !== $related->family_space_id) {
            $this->fail('Both People must belong to the same Family Space.');
        }
        if ($subject->id === $related->id) {
            $this->fail('A Person cannot have a relationship with themselves.');
        }

        [$subjectId, $relatedId] = $this->normalize($subject->id, $related->id, $type);
        $base = PersonRelationship::query()
            ->where('family_space_id', $subject->family_space_id)
            ->when($ignoreRelationshipId !== null, fn ($query) => $query->whereKeyNot($ignoreRelationshipId));

        if ((clone $base)
            ->where('type', $type->value)
            ->where('subject_person_id', $subjectId)
            ->where('related_person_id', $relatedId)
            ->exists()) {
            $this->fail('This relationship already exists.');
        }

        $directed = [
            RelationshipType::ParentOf->value,
            RelationshipType::GuardianOf->value,
            RelationshipType::StepParentOf->value,
            RelationshipType::GrandparentOf->value,
        ];
        if (in_array($type->value, $directed, true)
            && (clone $base)
                ->where('type', $type->value)
                ->where('subject_person_id', $relatedId)
                ->where('related_person_id', $subjectId)
                ->exists()) {
            $this->fail('A direct inverse relationship cycle is not valid.');
        }

        $exclusive = [
            RelationshipType::ParentOf->value,
            RelationshipType::PartnerOf->value,
            RelationshipType::SiblingOf->value,
            RelationshipType::GuardianOf->value,
            RelationshipType::StepParentOf->value,
            RelationshipType::GrandparentOf->value,
        ];
        if (in_array($type->value, $exclusive, true)
            && (clone $base)
                ->whereIn('type', $exclusive)
                ->where('type', '!=', $type->value)
                ->where(function ($query) use ($subjectId, $relatedId): void {
                    $query->where(function ($forward) use ($subjectId, $relatedId): void {
                        $forward->where('subject_person_id', $subjectId)
                            ->where('related_person_id', $relatedId);
                    })->orWhere(function ($reverse) use ($subjectId, $relatedId): void {
                        $reverse->where('subject_person_id', $relatedId)
                            ->where('related_person_id', $subjectId);
                    });
                })
                ->exists()) {
            $this->fail('This relationship contradicts an existing relationship for the same People.');
        }

        return ['subject_person_id' => $subjectId, 'related_person_id' => $relatedId];
    }

    /** @return array{string, string} */
    private function normalize(string $subjectId, string $relatedId, RelationshipType $type): array
    {
        if ($type->isSymmetric() && strcmp($subjectId, $relatedId) > 0) {
            return [$relatedId, $subjectId];
        }

        return [$subjectId, $relatedId];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['relationship' => [$message]]);
    }
}
