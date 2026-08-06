<?php

namespace App\Services;

use App\Models\FamilyCircle;
use App\Models\FamilyCirclePerson;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FamilyCircleManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $input */
    public function create(FamilySpace $familySpace, array $input, User $actor, Request $request): FamilyCircle
    {
        return DB::transaction(function () use ($familySpace, $input, $actor, $request): FamilyCircle {
            $circle = FamilyCircle::query()->create([
                'family_space_id' => $familySpace->id,
                'name' => trim((string) $input['name']),
                'description' => $this->description($input['description'] ?? null),
                'created_by' => $actor->id,
            ]);
            $this->audit->record('person.family_circle_created', $circle, $actor, $request);

            return $circle;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(FamilyCircle $circle, array $input, User $actor, Request $request): FamilyCircle
    {
        return DB::transaction(function () use ($circle, $input, $actor, $request): FamilyCircle {
            $locked = FamilyCircle::query()->lockForUpdate()->findOrFail($circle->id);
            $locked->update([
                'name' => trim((string) $input['name']),
                'description' => $this->description($input['description'] ?? null),
            ]);
            $this->audit->record('person.family_circle_changed', $locked, $actor, $request);

            return $locked;
        });
    }

    public function delete(FamilyCircle $circle, User $actor, Request $request): void
    {
        DB::transaction(function () use ($circle, $actor, $request): void {
            $locked = FamilyCircle::query()->lockForUpdate()->findOrFail($circle->id);
            $this->audit->record('person.family_circle_removed', $locked, $actor, $request, [
                'name' => $locked->name,
            ]);
            $locked->delete();
        });
    }

    public function addPerson(FamilyCircle $circle, Person $person, User $actor, Request $request): FamilyCirclePerson
    {
        return DB::transaction(function () use ($circle, $person, $actor, $request): FamilyCirclePerson {
            $lockedCircle = FamilyCircle::query()->lockForUpdate()->findOrFail($circle->id);
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
            abort_unless($lockedCircle->family_space_id === $lockedPerson->family_space_id, 404);

            $membership = FamilyCirclePerson::query()->firstOrCreate([
                'family_space_id' => $lockedCircle->family_space_id,
                'family_circle_id' => $lockedCircle->id,
                'person_id' => $lockedPerson->id,
            ], ['added_by' => $actor->id]);
            if ($membership->wasRecentlyCreated) {
                $this->audit->record('person.family_circle_person_added', $membership, $actor, $request);
            }

            return $membership;
        });
    }

    public function removePerson(FamilyCircle $circle, Person $person, User $actor, Request $request): void
    {
        DB::transaction(function () use ($circle, $person, $actor, $request): void {
            $membership = FamilyCirclePerson::query()
                ->where('family_space_id', $circle->family_space_id)
                ->where('family_circle_id', $circle->id)
                ->where('person_id', $person->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->audit->record('person.family_circle_person_removed', $membership, $actor, $request);
            $membership->delete();
        });
    }

    private function description(mixed $description): ?string
    {
        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }
}
