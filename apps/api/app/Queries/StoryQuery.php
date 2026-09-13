<?php

namespace App\Queries;

use App\Models\Person;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class StoryQuery
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly FamilyEventQuery $events,
        private readonly PersonQuery $people,
    ) {}

    /** @return Builder<Story> */
    public function visibleTo(User $actor): Builder
    {
        $photoIds = $this->photos->visibleTo($actor)->setEagerLoads([])->select('photos.id');
        $albumIds = $this->albums->visibleTo($actor)->setEagerLoads([])->select('albums.id');
        $eventIds = $this->events->visibleTo($actor)->setEagerLoads([])->select('events.id');
        $personIds = Gate::forUser($actor)->allows('viewAny', Person::class)
            ? $this->people->forCurrentFamilySpace()->setEagerLoads([])->select('people.id')
            : $this->people->forCurrentFamilySpace()->setEagerLoads([])
                ->whereHas('photoPeople', fn (Builder $links) => $links
                    ->where('status', 'approved')->whereIn('photo_id', clone $photoIds))
                ->select('people.id');

        return Story::query()->where(function (Builder $stories) use ($photoIds, $albumIds, $eventIds, $personIds): void {
            $stories->whereIn('photo_id', $photoIds)
                ->orWhereIn('album_id', $albumIds)
                ->orWhereIn('event_id', $eventIds)
                ->orWhereIn('person_id', $personIds);
        });
    }
}
