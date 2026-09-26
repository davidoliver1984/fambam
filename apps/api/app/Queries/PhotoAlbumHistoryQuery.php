<?php

namespace App\Queries;

use App\Models\AlbumPhoto;
use App\Models\AuditEvent;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\User;
use App\Services\PresentationThumbnailService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class PhotoAlbumHistoryQuery
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AlbumQuery $albums,
        private readonly PresentationThumbnailService $thumbnails,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forPhoto(Photo $photo, User $viewer): array
    {
        $events = $this->events($photo);
        if ($events->isEmpty()) {
            return [];
        }

        $albumIds = $events->pluck('albumId')->unique()->values();
        $visibleAlbums = $this->albums->visibleTo($viewer)->setEagerLoads([])
            ->whereIn('id', $albumIds)->get(['id', 'name'])->keyBy('id');
        if ($visibleAlbums->isEmpty()) {
            return [];
        }

        $visibleEvents = $events->filter(fn (PhotoAlbumHistoryEvent $event): bool => $visibleAlbums->has($event->albumId));
        $actorIds = $visibleEvents->pluck('actorUserId')->filter()->unique()->values();
        $familyActorIds = FamilySpaceMembership::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->whereIn('user_id', $actorIds)->pluck('user_id')->unique();
        $actors = User::query()->whereIn('id', $familyActorIds)->get(['id', 'name'])->keyBy('id');

        $links = $this->visibleActorLinks($familyActorIds->all(), $viewer);
        $portraitUrls = $this->thumbnails->forPeople(
            $links->pluck('person_id')->values()->all(),
            $viewer,
        );
        $currentAlbumIds = AlbumPhoto::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)
            ->whereIn('album_id', $visibleAlbums->keys())
            ->pluck('album_id')->flip();

        $items = [];
        foreach ($visibleEvents as $event) {
            $actor = $event->actorUserId === null ? null : $actors->get($event->actorUserId);
            $link = $actor === null ? null : $links->get($actor->id);
            if ($link !== null && $link->person !== null) {
                $displayName = $link->person->preferred_name;
                $personId = $link->person_id;
            } else {
                $displayName = $actor === null ? 'Someone' : $actor->name;
                $personId = null;
            }
            $album = $visibleAlbums->get($event->albumId);
            if ($album === null) {
                continue;
            }

            $items[] = [
                'event_type' => $event->eventType,
                'album' => ['id' => $album->id, 'name' => $album->name],
                'actor' => [
                    'display_name' => $displayName,
                    'person_id' => $personId,
                    'initials' => $this->initials($displayName),
                    'portrait_thumbnail_url' => $personId === null ? null : ($portraitUrls[$personId] ?? null),
                ],
                'created_at' => $event->createdAt,
                'is_current' => $currentAlbumIds->has($event->albumId),
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $actorIds
     * @return EloquentCollection<int, PersonAccountLink>
     */
    private function visibleActorLinks(array $actorIds, User $viewer): EloquentCollection
    {
        if (! Gate::forUser($viewer)->allows('viewAny', Person::class)) {
            return new EloquentCollection;
        }

        return PersonAccountLink::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->whereIn('user_id', $actorIds)
            ->with('person:id,family_space_id,preferred_name')
            ->get()->filter(fn (PersonAccountLink $link): bool => $link->person !== null
                && Gate::forUser($viewer)->allows('view', $link->person))
            ->keyBy('user_id');
    }

    /** @return Collection<int, PhotoAlbumHistoryEvent> */
    private function events(Photo $photo): Collection
    {
        if (DB::getDriverName() === 'pgsql') {
            return collect(DB::select(
                'SELECT * FROM app_photo_album_history_events(?, ?)',
                [$this->tenantContext->familySpace()->id, $photo->id],
            ))->map(fn (object $event): PhotoAlbumHistoryEvent => new PhotoAlbumHistoryEvent(
                isset($event->actor_user_id) ? (int) $event->actor_user_id : null,
                (string) $event->event_type,
                (string) $event->album_id,
                CarbonImmutable::parse((string) $event->created_at)->toAtomString(),
            ));
        }

        $currentLinks = AlbumPhoto::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)->get(['id', 'album_id'])->keyBy('id');

        $events = AuditEvent::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('subject_type', (new AlbumPhoto)->getMorphClass())
            ->whereIn('action', ['album.photo_added', 'album.photo_removed'])
            ->where(function ($query) use ($photo, $currentLinks): void {
                $query->where('metadata->photo_id', $photo->id);
                if ($currentLinks->isNotEmpty()) {
                    $query->orWhereIn('subject_id', $currentLinks->keys());
                }
            })->latest('created_at')->latest('id')->get();

        $result = collect();
        foreach ($events as $event) {
            $metadata = $event->metadata ?? [];
            $albumId = $metadata['album_id'] ?? $currentLinks->get($event->subject_id)?->album_id;
            if (! is_string($albumId)) {
                continue;
            }
            $result->push(new PhotoAlbumHistoryEvent(
                $event->actor_user_id,
                $event->action === 'album.photo_added' ? 'added' : 'removed',
                $albumId,
                $event->created_at->toAtomString(),
            ));
        }

        return $result;
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }
}
