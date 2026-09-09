<?php

namespace App\Queries;

use App\Enums\FamilyActivityType;
use App\Models\Album as AlbumModel;
use App\Models\FamilyActivity;
use App\Models\FamilyEvent as FamilyEventModel;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoStory;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class FamilyActivityQuery
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly FamilyEventQuery $events,
    ) {}

    /** @return list<array<string, mixed>> */
    public function recent(User $viewer, int $limit = 20): array
    {
        $activities = FamilyActivity::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->latest('created_at')->latest('id')->limit(100)->get();
        if ($activities->isEmpty()) {
            return [];
        }

        $albumIds = $activities->pluck('subject_album_id')->filter()->unique()->values();
        $eventIds = $activities->pluck('subject_event_id')->filter()->unique()->values();
        $storyIds = $activities->pluck('subject_story_id')->filter()->unique()->values();
        $photoIds = $activities->flatMap(fn (FamilyActivity $activity): array => $activity->photo_ids ?? [])
            ->unique()->values();

        $visibleAlbums = $this->albums->visibleTo($viewer)->whereIn('id', $albumIds)
            ->get(['id', 'name', 'event_id'])->keyBy('id');
        $visibleEvents = $this->events->visibleTo($viewer)->whereIn('id', $eventIds)
            ->get(['id', 'name'])->keyBy('id');
        $visiblePhotos = $this->photos->visibleTo($viewer)->whereIn('id', $photoIds)
            ->get(['photos.id', 'caption'])->keyBy('id');
        $visibleStories = PhotoStory::query()->whereIn('id', $storyIds)
            ->whereHas('photo', fn ($query) => $query->whereIn('photos.id', $visiblePhotos->keys()))
            ->get(['id', 'photo_id', 'body'])->keyBy('id');
        $peopleVisible = Gate::forUser($viewer)->allows('viewAny', Person::class);
        $subjectPeople = $peopleVisible
            ? Person::query()->whereIn('id', $activities->pluck('subject_person_id')->filter()->unique())
                ->get(['id', 'preferred_name'])->keyBy('id')
            : collect();
        $actorPeople = $peopleVisible
            ? Person::query()->whereIn('id', $activities->pluck('actor_person_id')->filter()->unique())
                ->get(['id', 'preferred_name'])->keyBy('id')
            : collect();
        $actors = User::query()->whereIn('id', $activities->pluck('actor_user_id')->unique())
            ->get(['id', 'name'])->keyBy('id');

        $items = collect();
        foreach ($activities as $activity) {
            $payload = $this->payload(
                $activity,
                $visibleAlbums,
                $visibleEvents,
                $visibleStories,
                $subjectPeople,
                $actors,
                $actorPeople,
                $visiblePhotos,
            );
            if ($payload !== null) {
                $items->push($payload);
            }
        }

        return $this->groupContributions($items)->take($limit)->values()->all();
    }

    /**
     * @param  Collection<int|string, AlbumModel>  $albums
     * @param  Collection<int|string, FamilyEventModel>  $events
     * @param  Collection<int|string, PhotoStory>  $stories
     * @param  Collection<int|string, Person>  $people
     * @param  Collection<int|string, User>  $actors
     * @param  Collection<int|string, Person>  $actorPeople
     * @param  Collection<int|string, Photo>  $photos
     * @return array<string, mixed>|null
     */
    private function payload(FamilyActivity $activity, Collection $albums, Collection $events, Collection $stories, Collection $people, Collection $actors, Collection $actorPeople, Collection $photos): ?array
    {
        $subject = match ($activity->action_type) {
            FamilyActivityType::PhotosAddedToAlbum, FamilyActivityType::AlbumCreated => $albums->get($activity->subject_album_id),
            FamilyActivityType::EventCreated => $events->get($activity->subject_event_id),
            FamilyActivityType::StoryAdded => $stories->get($activity->subject_story_id),
            FamilyActivityType::PersonIdentityConfirmed => $people->get($activity->subject_person_id),
        };
        if ($subject === null) {
            return null;
        }

        $visiblePhotoIds = collect($activity->photo_ids ?? [])->filter(fn (string $id): bool => $photos->has($id))->values();
        if ($activity->action_type === FamilyActivityType::PhotosAddedToAlbum && $visiblePhotoIds->isEmpty()) {
            return null;
        }
        if ($activity->action_type === FamilyActivityType::PersonIdentityConfirmed && $visiblePhotoIds->isEmpty()) {
            return null;
        }

        $actor = $actors->get($activity->actor_user_id);
        $actorName = $actor->name;
        $actorPersonId = null;
        if ($activity->actor_person_id !== null && $actorPeople->has($activity->actor_person_id)) {
            $actorPerson = $actorPeople->get($activity->actor_person_id);
            $actorName = $actorPerson->preferred_name;
            $actorPersonId = $actorPerson->id;
        }

        return [
            'id' => $activity->id,
            'action_type' => $activity->action_type->value,
            'actor' => [
                'user_id' => $activity->actor_user_id,
                'name' => $actorName,
                'person_id' => $actorPersonId,
            ],
            'subject' => $this->subjectPayload($activity, $subject),
            'contribution_batch_id' => $activity->contribution_batch_id,
            'photo_ids' => $visiblePhotoIds->all(),
            'photo_count' => $visiblePhotoIds->count(),
            'created_at' => $activity->created_at->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function subjectPayload(FamilyActivity $activity, object $subject): array
    {
        return match ($activity->action_type) {
            FamilyActivityType::PhotosAddedToAlbum, FamilyActivityType::AlbumCreated => [
                'type' => 'album', 'id' => $subject->id, 'label' => $subject->name,
            ],
            FamilyActivityType::EventCreated => [
                'type' => 'event', 'id' => $subject->id, 'label' => $subject->name,
            ],
            FamilyActivityType::StoryAdded => [
                'type' => 'story', 'id' => $subject->id, 'photo_id' => $subject->photo_id,
                'label' => mb_strimwidth($subject->body, 0, 100, '…'),
            ],
            FamilyActivityType::PersonIdentityConfirmed => [
                'type' => 'person', 'id' => $subject->id, 'label' => $subject->preferred_name,
            ],
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function groupContributions(Collection $items): Collection
    {
        $result = collect();
        $groupIndexes = [];
        foreach ($items as $item) {
            if ($item['action_type'] !== FamilyActivityType::PhotosAddedToAlbum->value
                || $item['contribution_batch_id'] === null) {
                $result->push($item);

                continue;
            }
            $key = implode(':', [
                $item['actor']['user_id'], $item['action_type'], $item['subject']['id'], $item['contribution_batch_id'],
            ]);
            if (! array_key_exists($key, $groupIndexes)) {
                $groupIndexes[$key] = $result->count();
                $result->push($item);

                continue;
            }
            $index = $groupIndexes[$key];
            $group = $result->get($index);
            $group['photo_ids'] = array_values(array_unique([...$group['photo_ids'], ...$item['photo_ids']]));
            $group['photo_count'] = count($group['photo_ids']);
            $result->put($index, $group);
        }

        return $result;
    }
}
