<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFamilyEventRequest;
use App\Http\Requests\UpdateFamilyEventRequest;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\User;
use App\Queries\FamilyEventQuery;
use App\Services\FamilyEventManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FamilyEventController extends Controller
{
    public function __construct(
        private readonly FamilyEventQuery $events,
        private readonly FamilyEventManager $manager,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        Gate::authorize('viewAny', FamilyEvent::class);

        return response()->json(['data' => $this->events->all()->map(
            fn (FamilyEvent $event): array => $this->payload($event),
        )]);
    }

    public function store(FamilySpace $familySpace, StoreFamilyEventRequest $request): JsonResponse
    {
        Gate::authorize('create', FamilyEvent::class);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->manager->create($familySpace, $actor, $request->validated(), $request),
        )], 201);
    }

    public function show(FamilySpace $familySpace, string $event): JsonResponse
    {
        $target = $this->events->find($event);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->payload($target, true)]);
    }

    public function update(
        FamilySpace $familySpace,
        string $event,
        UpdateFamilyEventRequest $request,
    ): JsonResponse {
        $target = $this->events->find($event);
        Gate::authorize('update', $target);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->manager->update($target, $actor, $request->validated(), $request),
            true,
        )]);
    }

    public function duplicateCandidates(FamilySpace $familySpace, string $event): JsonResponse
    {
        $target = $this->events->find($event);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->events->duplicateCandidates($target)
            ->map(fn (FamilyEvent $event): array => $this->payload($event))]);
    }

    public function forPerson(FamilySpace $familySpace, string $person): JsonResponse
    {
        Gate::authorize('viewAny', FamilyEvent::class);
        $target = Person::query()->where('family_space_id', $familySpace->id)->findOrFail($person);

        return response()->json(['data' => $this->events->forPerson($target)->map(
            fn (FamilyEvent $event): array => $this->payload($event),
        )]);
    }

    /** @return array<string, mixed> */
    private function payload(FamilyEvent $event, bool $detailed = false): array
    {
        $event->loadMissing('creator:id,name');
        $payload = [
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'starts_on' => $event->starts_on?->format('Y-m-d'),
            'ends_on' => $event->ends_on?->format('Y-m-d'),
            'location' => $event->location,
            'status' => $event->status->value,
            'created_by' => $event->created_by,
            'creator' => $event->creator === null ? null : ['id' => $event->creator->id, 'name' => $event->creator->name],
            'permissions' => ['can_update' => Gate::allows('update', $event)],
        ];
        if (! $detailed) {
            return $payload;
        }

        $event->loadMissing('albums.creator:id,name');
        $payload['albums'] = $event->albums->map(fn ($album): array => [
            'id' => $album->id, 'name' => $album->name, 'visibility' => $album->visibility->value,
        ])->values();
        $payload['attendees'] = $this->events->attendees($event)->map(fn (Person $person): array => [
            'id' => $person->id, 'preferred_name' => $person->preferred_name,
        ])->values();

        return $payload;
    }
}
