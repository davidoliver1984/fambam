<?php

namespace App\Queries;

use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoStory;
use App\Models\User;
use App\Search\Summaries\PhotoStorySearchSummary;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class HomepageMemoryQuery
{
    private const int LIMIT = 8;

    private const int RECENT_DAYS = 30;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly FamilyEventQuery $events,
    ) {}

    /** @return array{recent_days: int, people: list<array<string, mixed>>, stories: list<array<string, mixed>>} */
    public function forViewer(User $viewer): array
    {
        $cutoff = CarbonImmutable::now()->subDays(self::RECENT_DAYS);

        return [
            'recent_days' => self::RECENT_DAYS,
            'people' => $this->people($viewer, $cutoff),
            'stories' => $this->stories($viewer, $cutoff),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function people(User $viewer, CarbonImmutable $cutoff): array
    {
        if (Gate::forUser($viewer)->denies('viewAny', Person::class)) {
            return [];
        }

        $visiblePhotoIds = $this->visiblePhotoIds($viewer);
        $familySpaceId = $this->tenantContext->familySpace()->id;
        $approvedPeople = fn () => DB::table('photo_people as pp')
            ->join('photos as p', function ($join): void {
                $join->on('p.id', '=', 'pp.photo_id')
                    ->on('p.family_space_id', '=', 'pp.family_space_id');
            })
            ->where('pp.family_space_id', $familySpaceId)
            ->where('pp.status', 'approved')
            ->where('p.do_not_resurface', false)
            ->whereIn('p.id', clone $visiblePhotoIds);

        $photoAssociations = $approvedPeople()
            ->where('pp.created_at', '>=', $cutoff)
            ->selectRaw("pp.person_id, pp.created_at as occurred_at, 'photo' as memory_kind, pp.id as memory_id");
        $stories = $approvedPeople()
            ->join('photo_stories as ps', function ($join): void {
                $join->on('ps.photo_id', '=', 'p.id')
                    ->on('ps.family_space_id', '=', 'p.family_space_id');
            })
            ->whereNull('ps.deleted_at')
            ->where('ps.created_at', '>=', $cutoff)
            ->selectRaw("pp.person_id, ps.created_at as occurred_at, 'story' as memory_kind, ps.id as memory_id");
        $albumAssociations = $approvedPeople()
            ->join('album_photos as ap', function ($join): void {
                $join->on('ap.photo_id', '=', 'p.id')
                    ->on('ap.family_space_id', '=', 'p.family_space_id');
            })
            ->where('ap.created_at', '>=', $cutoff)
            ->selectRaw("pp.person_id, ap.created_at as occurred_at, 'album' as memory_kind, ap.id as memory_id");
        $eventAssociations = $approvedPeople()
            ->join('album_photos as ap', function ($join): void {
                $join->on('ap.photo_id', '=', 'p.id')
                    ->on('ap.family_space_id', '=', 'p.family_space_id');
            })
            ->join('albums as a', function ($join): void {
                $join->on('a.id', '=', 'ap.album_id')
                    ->on('a.family_space_id', '=', 'ap.family_space_id');
            })
            ->whereNotNull('a.event_id')
            ->where('ap.created_at', '>=', $cutoff)
            ->selectRaw("pp.person_id, ap.created_at as occurred_at, 'event' as memory_kind, ap.id as memory_id");

        $activity = $photoAssociations
            ->unionAll($stories)
            ->unionAll($albumAssociations)
            ->unionAll($eventAssociations);

        return DB::query()->fromSub($activity, 'recent_memories')
            ->join('people', 'people.id', '=', 'recent_memories.person_id')
            ->where('people.family_space_id', $familySpaceId)
            ->whereNull('people.deleted_at')
            ->groupBy('people.id', 'people.preferred_name')
            ->selectRaw('people.id, people.preferred_name, count(*) as memory_count, max(recent_memories.occurred_at) as latest_at')
            ->orderByDesc('latest_at')
            ->orderBy('people.id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'person_id' => (string) $row->id,
                'preferred_name' => (string) $row->preferred_name,
                'memory_count' => (int) $row->memory_count,
                'latest_at' => CarbonImmutable::parse((string) $row->latest_at)->toAtomString(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function stories(User $viewer, CarbonImmutable $cutoff): array
    {
        $storyRows = PhotoStory::query()
            ->whereIn('photo_id', $this->visiblePhotoIds($viewer))
            ->where('created_at', '>=', $cutoff)
            ->with(['author:id,name', 'photo:id,family_space_id,media_upload_id,caption,primary_event_id'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        if ($storyRows->isEmpty()) {
            return [];
        }

        $photoIds = $storyRows->pluck('photo_id')->unique()->values()->all();
        $peopleByPhoto = Gate::forUser($viewer)->allows('viewAny', Person::class)
            ? $this->peopleForPhotos($photoIds)
            : [];
        $albumsByPhoto = $this->albumsForPhotos($viewer, $photoIds);
        $eventsByPhoto = $this->eventsForPhotos($viewer, $photoIds);

        return $storyRows->map(function (PhotoStory $story) use ($peopleByPhoto, $albumsByPhoto, $eventsByPhoto): array {
            $summary = (new PhotoStorySearchSummary(
                $story->id,
                $story->photo->id,
                $story->photo->caption,
                $story->photo->media_upload_id,
                Str::limit(trim($story->body), 240),
                $story->created_at?->toAtomString() ?? '',
            ))->toArray();

            return [
                ...$summary,
                'author' => [
                    'id' => $story->author?->id,
                    'name' => $story->author?->name ?? 'Former family member',
                ],
                'people' => $peopleByPhoto[$story->photo->id] ?? [],
                'albums' => $albumsByPhoto[$story->photo->id] ?? [],
                'events' => $eventsByPhoto[$story->photo->id] ?? [],
            ];
        })->all();
    }

    /** @return Builder<Photo> */
    private function visiblePhotoIds(User $viewer): Builder
    {
        return $this->photos->visibleTo($viewer)->setEagerLoads([])
            ->where('photos.do_not_resurface', false)
            ->select('photos.id');
    }

    /** @param list<string> $photoIds
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function peopleForPhotos(array $photoIds): array
    {
        return DB::table('photo_people')
            ->join('people', function ($join): void {
                $join->on('people.id', '=', 'photo_people.person_id')
                    ->on('people.family_space_id', '=', 'photo_people.family_space_id');
            })
            ->whereIn('photo_people.photo_id', $photoIds)
            ->where('photo_people.status', 'approved')
            ->whereNull('people.deleted_at')
            ->orderBy('people.preferred_name')
            ->get(['photo_people.photo_id', 'people.id', 'people.preferred_name'])
            ->groupBy('photo_id')
            ->map(fn ($rows): array => $rows->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->preferred_name,
            ])->all())->all();
    }

    /** @param list<string> $photoIds
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function albumsForPhotos(User $viewer, array $photoIds): array
    {
        $albums = $this->albums->visibleTo($viewer)->setEagerLoads([])
            ->whereHas('photos', fn (Builder $photos) => $photos->whereIn('photos.id', $photoIds))
            ->with(['photos' => fn ($photos) => $photos->whereIn('photos.id', $photoIds)->select('photos.id')])
            ->get(['albums.id', 'albums.name']);

        $byPhoto = [];
        foreach ($albums as $album) {
            foreach ($album->photos as $photo) {
                $byPhoto[$photo->id][] = ['id' => $album->id, 'name' => $album->name];
            }
        }

        return $byPhoto;
    }

    /** @param list<string> $photoIds
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function eventsForPhotos(User $viewer, array $photoIds): array
    {
        $events = $this->events->visibleTo($viewer)
            ->where(function (Builder $query) use ($photoIds): void {
                $query->whereHas('primaryPhotos', fn (Builder $photos) => $photos->whereIn('photos.id', $photoIds))
                    ->orWhereHas('albums.photos', fn (Builder $photos) => $photos->whereIn('photos.id', $photoIds));
            })->get(['events.id', 'events.name']);

        $byPhoto = [];
        foreach ($photoIds as $photoId) {
            foreach ($events as $event) {
                $matches = Photo::query()->whereKey($photoId)->where(function (Builder $photo) use ($event): void {
                    $photo->where('primary_event_id', $event->id)
                        ->orWhereHas('albums', fn (Builder $album) => $album->where('albums.event_id', $event->id));
                })->exists();
                if ($matches) {
                    $byPhoto[$photoId][] = ['id' => $event->id, 'name' => $event->name];
                }
            }
        }

        return $byPhoto;
    }
}
