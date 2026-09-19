<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\Http\Requests\StoreFamilyEventRequest;
use App\Http\Requests\UpdateFamilyEventRequest;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Story;
use App\Models\User;
use App\Queries\FamilyEventQuery;
use App\Queries\PhotoQuery;
use App\Services\EventAccess;
use App\Services\FamilyEventManager;
use App\Stories\RichTextPresenter;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilyEventController extends Controller
{
    public function __construct(
        private readonly FamilyEventQuery $events,
        private readonly PhotoQuery $photos,
        private readonly FamilyEventManager $manager,
        private readonly EventAccess $access,
        private readonly TenantContext $tenantContext,
        private readonly RichTextPresenter $presenter,
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

    public function deleted(): JsonResponse
    {
        Gate::authorize('viewAny', FamilyEvent::class);
        abort_unless($this->tenantContext->membership()->role->canManageMembers(), 403);

        return response()->json(['data' => $this->events->deleted()->map(
            fn (FamilyEvent $event): array => $this->payload($event),
        )]);
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
        Gate::authorize('reviewDuplicates', $target);

        return response()->json(['data' => $this->events->duplicateCandidates($target)
            ->map(fn (FamilyEvent $event): array => $this->payload($event))]);
    }

    public function destroy(FamilySpace $familySpace, string $event, Request $request): JsonResponse
    {
        $target = $this->events->find($event);
        Gate::authorize('delete', $target);
        /** @var User $actor */
        $actor = $request->user();
        $this->manager->delete($target, $actor, $request);

        return response()->json(null, 204);
    }

    public function restore(FamilySpace $familySpace, string $event, Request $request): JsonResponse
    {
        $target = $this->events->findDeleted($event);
        Gate::authorize('restore', $target);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload($this->manager->restore($target, $actor, $request), true)]);
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
            'description' => $event->description_plain_text,
            'description_document' => $event->description,
            'description_html' => $this->presenter->html($event->description, $event, 'event_description_mentions',
                'event_id', $this->familySlug(), $this->actor(), $event),
            'starts_on' => $event->starts_on?->format('Y-m-d'),
            'ends_on' => $event->ends_on?->format('Y-m-d'),
            'location' => $event->location,
            'status' => $event->status->value,
            'created_by' => $event->created_by,
            'creator' => $event->creator === null ? null : ['id' => $event->creator->id, 'name' => $event->creator->name],
            'presentation' => $this->presentation($event),
            'permissions' => ['can_update' => Gate::allows('update', $event),
                'can_manage_admissions' => Gate::allows('manageAdmissions', $event),
                'can_review_duplicates' => Gate::allows('reviewDuplicates', $event),
                'can_manage_exports' => Gate::allows('manageExports', $event),
                'can_delete' => ! $event->trashed() && Gate::allows('delete', $event),
                'can_restore' => $event->trashed() && Gate::allows('restore', $event),
                'can_create_album' => ! $event->trashed() && Gate::allows('create', Album::class)],
        ];
        if (! $detailed) {
            return $payload;
        }

        $event->loadMissing('albums.creator:id,name');
        $albums = $event->albums;
        if ($this->tenantContext->membership()->role === FamilySpaceRole::Guest) {
            $membership = $this->tenantContext->membership();
            $albums = $albums->filter(fn ($album): bool => $this->access->guestMayViewAlbum($album, $membership));
        } elseif ($this->tenantContext->membership()->role === FamilySpaceRole::Contributor) {
            $albums = $albums->filter(fn ($album): bool => Gate::allows('view', $album));
        }
        $payload['albums'] = $albums->map(fn ($album): array => [
            'id' => $album->id, 'name' => $album->name, 'visibility' => $album->visibility->value,
            'guest_participation' => $album->guest_participation->value,
        ])->values();
        $payload['attendees'] = in_array($this->tenantContext->membership()->role,
            [FamilySpaceRole::Guest, FamilySpaceRole::Contributor], true)
            ? [] : $this->events->attendees($event)->map(fn (Person $person): array => [
                'id' => $person->id, 'preferred_name' => $person->preferred_name,
            ])->values();
        $payload['people'] = in_array($this->tenantContext->membership()->role,
            [FamilySpaceRole::Guest, FamilySpaceRole::Contributor], true)
            ? [] : $event->people()->get(['people.id', 'preferred_name'])->map(fn (Person $person): array => [
                'id' => $person->id, 'name' => $person->preferred_name,
            ])->values();

        return $payload;
    }

    /** @return array{preview: array{photo_id: string, media_upload_id: string}|null, photo_count: int, album_count: int, story_count: int, people_count: int} */
    private function presentation(FamilyEvent $event): array
    {
        $visiblePhotos = $this->photos->visibleTo($this->actor())
            ->where(function ($query) use ($event): void {
                $query->where('primary_event_id', $event->id)
                    ->orWhereHas('albums', fn ($albums) => $albums->where('albums.event_id', $event->id));
            })
            ->orderByRaw('historical_date IS NULL')
            ->orderBy('historical_date')
            ->orderBy('id')
            ->get(['photos.id', 'photos.media_upload_id']);
        $preview = $visiblePhotos->first();
        $role = $this->tenantContext->membership()->role;
        $visibleAlbumCount = $event->albums()->get()
            ->filter(fn (Album $album): bool => Gate::allows('view', $album))
            ->count();
        $visibleStoryCount = Story::query()->where('event_id', $event->id)->get()
            ->filter(fn (Story $story): bool => Gate::allows('view', $story))
            ->count();

        return [
            'preview' => $preview === null ? null : [
                'photo_id' => $preview->id,
                'media_upload_id' => $preview->media_upload_id,
            ],
            'photo_count' => $visiblePhotos->count(),
            'album_count' => $visibleAlbumCount,
            'story_count' => $visibleStoryCount,
            'people_count' => in_array($role, [FamilySpaceRole::Guest, FamilySpaceRole::Contributor], true)
                ? 0 : $this->events->attendees($event)->count(),
        ];
    }

    private function familySlug(): string
    {
        $familySpace = request()->route('familySpace');

        return $familySpace instanceof FamilySpace ? $familySpace->slug : (string) $familySpace;
    }

    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
