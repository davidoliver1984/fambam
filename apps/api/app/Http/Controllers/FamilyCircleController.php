<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddCirclePersonRequest;
use App\Http\Requests\StoreFamilyCircleRequest;
use App\Models\FamilyCircle;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\User;
use App\Queries\FamilyCircleQuery;
use App\Services\FamilyCircleManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilyCircleController extends Controller
{
    public function __construct(
        private readonly FamilyCircleQuery $circles,
        private readonly FamilyCircleManager $manager,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        Gate::authorize('viewAny', FamilyCircle::class);

        return response()->json(['data' => $this->circles->all()->map($this->payload(...))]);
    }

    public function store(FamilySpace $familySpace, StoreFamilyCircleRequest $request): JsonResponse
    {
        Gate::authorize('create', FamilyCircle::class);
        /** @var User $actor */
        $actor = $request->user();
        $circle = $this->manager->create($familySpace, $request->validated(), $actor, $request);

        return response()->json(['data' => $this->payload($circle)], 201);
    }

    public function update(FamilySpace $familySpace, string $circle, StoreFamilyCircleRequest $request): JsonResponse
    {
        $target = $this->circles->find($circle);
        Gate::authorize('update', $target);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->manager->update($target, $request->validated(), $actor, $request),
        )]);
    }

    public function destroy(FamilySpace $familySpace, string $circle, Request $request): JsonResponse
    {
        $target = $this->circles->find($circle);
        Gate::authorize('delete', $target);
        /** @var User $actor */
        $actor = $request->user();
        $this->manager->delete($target, $actor, $request);

        return response()->json(null, 204);
    }

    public function addPerson(
        FamilySpace $familySpace,
        string $circle,
        AddCirclePersonRequest $request,
    ): JsonResponse {
        $target = $this->circles->find($circle);
        Gate::authorize('update', $target);
        $person = $this->circles->findPerson((string) $request->validated('person_id'));
        /** @var User $actor */
        $actor = $request->user();
        $this->manager->addPerson($target, $person, $actor, $request);

        return response()->json(['data' => $this->payload($this->circles->find($circle))], 201);
    }

    public function removePerson(
        FamilySpace $familySpace,
        string $circle,
        string $person,
        Request $request,
    ): JsonResponse {
        $target = $this->circles->find($circle);
        Gate::authorize('update', $target);
        $targetPerson = $this->circles->findPerson($person);
        /** @var User $actor */
        $actor = $request->user();
        $this->manager->removePerson($target, $targetPerson, $actor, $request);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(FamilyCircle $circle): array
    {
        $circle->loadMissing(['people' => fn ($query) => $query->orderBy('preferred_name')]);

        return [
            'id' => $circle->id,
            'name' => $circle->name,
            'description' => $circle->description,
            'people' => $circle->people->map(fn (Person $person): array => [
                'id' => $person->id,
                'preferred_name' => $person->preferred_name,
            ])->values(),
        ];
    }
}
