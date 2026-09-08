<?php

namespace App\Search;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoStory;
use App\Models\User;
use App\Queries\AlbumQuery;
use App\Queries\FamilyEventQuery;
use App\Queries\PersonQuery;
use App\Queries\PhotoQuery;
use App\Search\Summaries\AlbumSearchSummary;
use App\Search\Summaries\EventSearchSummary;
use App\Search\Summaries\PersonSearchSummary;
use App\Search\Summaries\PhotoSearchSummary;
use App\Search\Summaries\PhotoStorySearchSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class DatabaseDiscoveryService implements DiscoveryService
{
    private const LIMIT = 24;

    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly FamilyEventQuery $events,
        private readonly PersonQuery $people,
    ) {}

    public function fromPerson(Person $person, User $actor): array
    {
        $photos = $this->visiblePhotos($actor)
            ->whereHas('photoPeople', fn (Builder $people) => $people
                ->where('person_id', $person->id)->where('status', 'approved'))
            ->orderBy('photos.id')->limit(self::LIMIT)->get();
        $photoIds = $photos->pluck('id');

        return [
            'photos' => $photos->map($this->photoSummary(...))->all(),
            'albums' => $this->visibleAlbums($actor)->whereHas('photos', fn (Builder $query) => $query
                ->whereIn('photos.id', $photoIds))->orderBy('albums.name')->orderBy('albums.id')
                ->limit(self::LIMIT)->get()
                ->map($this->albumSummary(...))->all(),
            'events' => $this->eventsForPhotos($actor, $photoIds->all())
                ->limit(self::LIMIT)->get()->map($this->eventSummary(...))->all(),
            'stories' => $this->storiesForPhotos($photoIds->all()),
            'people' => $this->peopleForPhotos($actor, $photoIds->all(), $person->id),
        ];
    }

    public function fromPhoto(Photo $photo, User $actor): array
    {
        $photoIds = [$photo->id];

        return [
            'people' => $this->peopleForPhotos($actor, $photoIds),
            'albums' => $this->visibleAlbums($actor)->whereHas('photos', fn (Builder $query) => $query
                ->where('photos.id', $photo->id))->orderBy('albums.name')->orderBy('albums.id')
                ->limit(self::LIMIT)->get()
                ->map($this->albumSummary(...))->all(),
            'events' => $this->eventsForPhotos($actor, $photoIds)
                ->limit(self::LIMIT)->get()->map($this->eventSummary(...))->all(),
            'stories' => $this->storiesForPhotos($photoIds),
        ];
    }

    public function fromAlbum(Album $album, User $actor): array
    {
        $photos = $this->visiblePhotos($actor)->whereHas('albums', fn (Builder $albums) => $albums
            ->where('albums.id', $album->id))->orderBy('photos.id')->limit(self::LIMIT)->get();
        $photoIds = $photos->pluck('id')->all();
        $events = $album->event_id === null
            ? []
            : $this->events->visibleTo($actor)->where('events.id', $album->event_id)
                ->limit(1)->get()->map($this->eventSummary(...))->all();

        return [
            'photos' => $photos->map($this->photoSummary(...))->all(),
            'people' => $this->peopleForPhotos($actor, $photoIds),
            'events' => $events,
        ];
    }

    public function fromEvent(FamilyEvent $event, User $actor): array
    {
        $albums = $this->visibleAlbums($actor)->where('albums.event_id', $event->id)
            ->orderBy('albums.name')->orderBy('albums.id')->limit(self::LIMIT)->get();
        $photos = $this->visiblePhotos($actor)->where(function (Builder $query) use ($event): void {
            $query->where('primary_event_id', $event->id)
                ->orWhereHas('albums', fn (Builder $albums) => $albums->where('albums.event_id', $event->id));
        })->orderBy('photos.id')->limit(self::LIMIT)->get();
        $photoIds = $photos->pluck('id')->all();

        return [
            'albums' => $albums->map($this->albumSummary(...))->all(),
            'photos' => $photos->map($this->photoSummary(...))->all(),
            'people' => $this->peopleForPhotos($actor, $photoIds),
        ];
    }

    /** @return Builder<Photo> */
    private function visiblePhotos(User $actor): Builder
    {
        return $this->photos->visibleTo($actor)
            ->setEagerLoads([])
            ->with(['photoPeople' => fn ($query) => $query
                ->where('status', 'approved')->whereHas('person')
                ->with('person:id,preferred_name')]);
    }

    /** @return Builder<Album> */
    private function visibleAlbums(User $actor): Builder
    {
        return $this->albums->visibleTo($actor)->setEagerLoads([]);
    }

    /** @param list<string> $photoIds
     * @return Builder<FamilyEvent>
     */
    private function eventsForPhotos(User $actor, array $photoIds): Builder
    {
        return $this->events->visibleTo($actor)->where(function (Builder $events) use ($photoIds): void {
            $events->whereHas('primaryPhotos', fn (Builder $photos) => $photos->whereIn('photos.id', $photoIds))
                ->orWhereHas('albums.photos', fn (Builder $photos) => $photos->whereIn('photos.id', $photoIds));
        })->orderByRaw('starts_on IS NULL')->orderBy('starts_on')->orderBy('events.id');
    }

    /** @param list<string> $photoIds
     * @return list<array<string, mixed>>
     */
    private function storiesForPhotos(array $photoIds): array
    {
        return PhotoStory::query()->whereIn('photo_id', $photoIds)->with('photo:id,media_upload_id,caption')
            ->latest('created_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn (PhotoStory $story): array => (new PhotoStorySearchSummary(
                $story->id,
                $story->photo->id,
                $story->photo->caption,
                $story->photo->media_upload_id,
                Str::limit(trim($story->body), 240),
                $story->created_at?->toAtomString() ?? '',
            ))->toArray())->all();
    }

    /** @param list<string> $photoIds
     * @return list<array<string, mixed>>
     */
    private function peopleForPhotos(User $actor, array $photoIds, ?string $except = null): array
    {
        if (Gate::forUser($actor)->denies('viewAny', Person::class)) {
            return [];
        }

        return $this->people->forCurrentFamilySpace()->setEagerLoads([])
            ->whereHas('photoPeople', fn (Builder $associations) => $associations
                ->whereIn('photo_id', $photoIds)->where('status', 'approved'))
            ->when($except !== null, fn (Builder $query) => $query->where('people.id', '!=', $except))
            ->orderBy('preferred_name')->orderBy('id')->limit(self::LIMIT)->get()
            ->map(fn (Person $person): array => (new PersonSearchSummary(
                $person->id,
                $person->preferred_name,
            ))->toArray())->all();
    }

    /** @return array<string, mixed> */
    private function photoSummary(Photo $photo): array
    {
        $date = $photo->historical_date;
        $precision = $photo->historical_date_precision?->value;

        return (new PhotoSearchSummary(
            $photo->id,
            $photo->media_upload_id,
            $photo->caption,
            $photo->description,
            $photo->location_description,
            $date === null ? null : [
                'precision' => $precision ?? 'unknown',
                'value' => match ($precision) {
                    'month' => $date->format('Y-m'),
                    'year' => $date->format('Y'),
                    'decade' => $date->format('Y').'s',
                    'unknown', null => null,
                    default => $date->format('Y-m-d'),
                },
            ],
            $photo->photoPeople->map(fn ($association): array => [
                'id' => $association->person->id,
                'preferred_name' => $association->person->preferred_name,
            ])->all(),
        ))->toArray();
    }

    /** @return array<string, mixed> */
    private function albumSummary(Album $album): array
    {
        return (new AlbumSearchSummary(
            $album->id,
            $album->name,
            $album->description,
            $album->visibility->value,
            $album->event_id,
        ))->toArray();
    }

    /** @return array<string, mixed> */
    private function eventSummary(FamilyEvent $event): array
    {
        return (new EventSearchSummary(
            $event->id,
            $event->name,
            $event->description,
            $event->location,
            $event->starts_on?->format('Y-m-d'),
            $event->ends_on?->format('Y-m-d'),
        ))->toArray();
    }
}
