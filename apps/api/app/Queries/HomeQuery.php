<?php

namespace App\Queries;

use App\Enums\FamilyActivityType;
use App\Enums\MediaUploadState;
use App\Media\MediaDeliveryAuthorization;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Story;
use App\Models\User;
use App\Services\MediaDeliveryManager;
use App\Stories\StoryHeading;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HomeQuery
{
    private const int LATEST_PHOTO_LIMIT = 15;

    private const int ACTIVITY_LIMIT = 20;

    public function __construct(
        private readonly FamilyActivityQuery $activities,
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly StoryQuery $stories,
        private readonly DateMemoryQuery $dateMemories,
        private readonly MediaDeliveryManager $delivery,
        private readonly StoryHeading $storyHeadings,
    ) {}

    /** @return array{activity: list<array<string, mixed>>, latest_photos: list<array<string, mixed>>, on_this_day: array<string, mixed>|null} */
    public function forViewer(User $viewer, CarbonImmutable $today): array
    {
        return [
            'activity' => $this->activity($viewer),
            'latest_photos' => $this->latestPhotos($viewer),
            'on_this_day' => $this->onThisDay($viewer, $today),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activity(User $viewer): array
    {
        $items = collect($this->activities->recent($viewer, self::ACTIVITY_LIMIT, [
            FamilyActivityType::PhotosAddedToAlbum,
            FamilyActivityType::StoryAdded,
        ]))
            ->filter(fn (array $item): bool => in_array($item['action_type'], [
                FamilyActivityType::PhotosAddedToAlbum->value,
                FamilyActivityType::StoryAdded->value,
            ], true))->values();
        if ($items->isEmpty()) {
            return [];
        }

        $albumIds = $items->where('action_type', FamilyActivityType::PhotosAddedToAlbum->value)
            ->pluck('subject.id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $storyIds = $items->where('action_type', FamilyActivityType::StoryAdded->value)
            ->pluck('subject.id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $activityPhotoIds = $items->flatMap(fn (array $item): array => $item['photo_ids'])
            ->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();

        $albums = $this->albums->visibleTo($viewer)->whereIn('id', $albumIds)
            ->get(['id', 'name', 'description_plain_text', 'starts_on', 'ends_on', 'location'])
            ->keyBy('id');
        $visibleStoryIds = $this->stories->visibleTo($viewer)->setEagerLoads([])
            ->whereIn('stories.id', $storyIds)->pluck('stories.id');
        $stories = Story::query()
            ->leftJoin('people as story_people', fn (JoinClause $join) => $join
                ->on('story_people.id', '=', 'stories.person_id')
                ->on('story_people.family_space_id', '=', 'stories.family_space_id'))
            ->leftJoin('albums as story_albums', fn (JoinClause $join) => $join
                ->on('story_albums.id', '=', 'stories.album_id')
                ->on('story_albums.family_space_id', '=', 'stories.family_space_id'))
            ->leftJoin('events as story_events', fn (JoinClause $join) => $join
                ->on('story_events.id', '=', 'stories.event_id')
                ->on('story_events.family_space_id', '=', 'stories.family_space_id'))
            ->leftJoin('photos as story_photos', fn (JoinClause $join) => $join
                ->on('story_photos.id', '=', 'stories.photo_id')
                ->on('story_photos.family_space_id', '=', 'stories.family_space_id'))
            ->leftJoin('media_uploads as story_uploads', fn (JoinClause $join) => $join
                ->on('story_uploads.id', '=', 'story_photos.media_upload_id')
                ->on('story_uploads.family_space_id', '=', 'stories.family_space_id'))
            ->whereIn('stories.id', $visibleStoryIds)
            ->get([
                'stories.id', 'stories.person_id', 'stories.album_id', 'stories.event_id', 'stories.photo_id',
                'stories.body', 'stories.body_plain_text',
                DB::raw('coalesce(story_people.preferred_name, story_albums.name, story_events.name, story_photos.caption, story_uploads.client_filename) as subject_label'),
            ])
            ->keyBy('id');
        $photos = $this->presentationPhotos($viewer, $activityPhotoIds)->keyBy('id');

        $albumKeys = $albums->keys()->map(fn (mixed $id): string => (string) $id)->all();
        $storyKeys = $stories->keys()->map(fn (mixed $id): string => (string) $id)->all();
        $albumLove = $this->loveSummaries('album_id', $albumKeys, $viewer);
        $storyLove = $this->loveSummaries('story_id', $storyKeys, $viewer);
        $storyCommentCounts = $this->counts('story_comments', 'story_id', $storyKeys, true);
        $albumCommentCounts = $this->albumCommentCounts($viewer, $albumKeys);
        $storyMentionLabels = $this->storyMentionLabels($storyKeys);

        return $items->map(function (array $item) use (
            $albums,
            $stories,
            $photos,
            $albumLove,
            $storyLove,
            $storyCommentCounts,
            $albumCommentCounts,
            $storyMentionLabels,
        ): ?array {
            if ($item['action_type'] === FamilyActivityType::StoryAdded->value) {
                $story = $stories->get($item['subject']['id']);
                if (! $story instanceof Story) {
                    return null;
                }
                $subject = $this->storySubject($story);
                if ($subject === null) {
                    return null;
                }
                $mentionLabels = $storyMentionLabels[$story->id] ?? [];

                return [
                    ...$item,
                    'story' => [
                        'id' => $story->id,
                        'heading' => $this->storyHeadings->derive(
                            $story->body,
                            fn (string $mentionId): ?string => $mentionLabels[$mentionId] ?? null,
                        ),
                        'excerpt' => Str::limit(trim($story->body_plain_text), 240),
                        'subject' => $subject,
                    ],
                    'engagement' => [
                        ...($storyLove[$story->id] ?? ['love_count' => 0, 'loved_by_me' => false]),
                        'comment_count' => $storyCommentCounts[$story->id] ?? 0,
                    ],
                ];
            }

            $album = $albums->get($item['subject']['id']);
            if (! $album instanceof Album) {
                return null;
            }
            $featurePhoto = $this->featurePhotoId($item['photo_ids'], $photos);

            return [
                ...$item,
                'album' => [
                    'id' => $album->id,
                    'name' => $album->name,
                    'description' => $album->description_plain_text,
                    'starts_on' => $album->starts_on?->format('Y-m-d'),
                    'ends_on' => $album->ends_on?->format('Y-m-d'),
                    'location' => $album->location,
                ],
                'feature_photo' => is_string($featurePhoto)
                    ? $this->photoPayload($photos->get($featurePhoto))
                    : null,
                'engagement' => [
                    ...($albumLove[$album->id] ?? ['love_count' => 0, 'loved_by_me' => false]),
                    'comment_count' => $albumCommentCounts[$album->id] ?? 0,
                ],
            ];
        })->filter()->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function latestPhotos(User $viewer): array
    {
        return $this->presentationPhotoQuery($viewer)
            ->where('photos.do_not_resurface', false)
            ->latest('photos.created_at')->latest('photos.id')
            ->limit(self::LATEST_PHOTO_LIMIT)->get()
            ->map(fn (Photo $photo): array => $this->photoPayload($photo))
            ->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function onThisDay(User $viewer, CarbonImmutable $today): ?array
    {
        $memory = $this->dateMemories->forDate($viewer, $today, 1)[0] ?? null;
        if ($memory === null) {
            return null;
        }
        $photo = $this->presentationPhotoQuery($viewer)->find($memory['photo_id']);
        if (! $photo instanceof Photo) {
            return null;
        }

        return [...$memory, 'photo' => $this->photoPayload($photo)];
    }

    /** @param list<string> $photoIds
     * @return Collection<int, Photo>
     */
    private function presentationPhotos(User $viewer, array $photoIds): Collection
    {
        if ($photoIds === []) {
            return collect();
        }

        return $this->presentationPhotoQuery($viewer)->whereIn('photos.id', $photoIds)->get();
    }

    /** @param array<mixed> $photoIds
     * @param  Collection<string, Photo>  $photos
     */
    private function featurePhotoId(array $photoIds, Collection $photos): ?string
    {
        $ids = array_values(array_filter($photoIds, is_string(...)));
        sort($ids);
        foreach ($ids as $id) {
            if ($photos->has($id)) {
                return $id;
            }
        }

        return null;
    }

    /** @return Builder<Photo> */
    private function presentationPhotoQuery(User $viewer): Builder
    {
        return $this->photos->visibleTo($viewer)->setEagerLoads([])
            ->with(['mediaUpload:id,state,canonical_object_key,canonical_mime_type,client_filename',
                'activeVersion:id,family_space_id,photo_id,derived_object_key'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('active_photo_version_id')
                    ->orWhereHas('mediaUpload', fn (Builder $uploads) => $uploads
                        ->where('state', MediaUploadState::Ready->value)
                        ->whereNotNull('canonical_object_key'));
            })
            ->select(['photos.id', 'photos.media_upload_id', 'photos.active_photo_version_id', 'photos.caption', 'photos.created_at']);
    }

    /** @return array<string, mixed> */
    private function photoPayload(Photo $photo): array
    {
        $authorization = $this->delivery->photoPresentation($photo);

        return [
            'id' => $photo->id,
            'media_upload_id' => $photo->media_upload_id,
            'active_photo_version_id' => $photo->active_photo_version_id,
            'alt' => $photo->caption,
            'presentation' => $this->authorizationPayload($authorization),
        ];
    }

    /** @return array{url: string, method: string, expires_at: string} */
    private function authorizationPayload(MediaDeliveryAuthorization $authorization): array
    {
        return [
            'url' => $authorization->url,
            'method' => 'GET',
            'expires_at' => $authorization->expiresAt->toAtomString(),
        ];
    }

    /** @param list<string> $ids
     * @return array<string, int>
     */
    private function counts(string $table, string $column, array $ids, bool $excludeDeleted = false): array
    {
        if ($ids === []) {
            return [];
        }
        $query = DB::table($table)->whereIn($column, $ids);
        if ($excludeDeleted) {
            $query->whereNull('deleted_at');
        }

        return $query->groupBy($column)->selectRaw("{$column}, count(*) as aggregate")->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)->all();
    }

    /** @param list<string> $ids
     * @return array<string, array{love_count: int, loved_by_me: bool}>
     */
    private function loveSummaries(string $column, array $ids, User $viewer): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('reactions')->whereIn($column, $ids)->where('reaction', 'love')
            ->groupBy($column)
            ->selectRaw("{$column}, count(*) as aggregate, max(case when user_id = ? then 1 else 0 end) as loved_by_me", [$viewer->id])
            ->get()->mapWithKeys(fn (object $row): array => [
                (string) $row->{$column} => [
                    'love_count' => (int) $row->aggregate,
                    'loved_by_me' => (bool) $row->loved_by_me,
                ],
            ])->all();
    }

    /** @param list<string> $storyIds
     * @return array<string, array<string, string>>
     */
    private function storyMentionLabels(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        return DB::table('story_person_mentions')
            ->join('people', function (JoinClause $join): void {
                $join->on('people.id', '=', 'story_person_mentions.person_id')
                    ->on('people.family_space_id', '=', 'story_person_mentions.family_space_id');
            })
            ->whereIn('story_person_mentions.story_id', $storyIds)
            ->whereNull('people.deleted_at')
            ->get(['story_person_mentions.story_id', 'story_person_mentions.mention_id', 'people.preferred_name'])
            ->groupBy('story_id')
            ->map(fn (Collection $rows): array => $rows->pluck('preferred_name', 'mention_id')
                ->map(fn (mixed $label): string => (string) $label)->all())
            ->all();
    }

    /** @return array{type: string, id: string, label: string}|null */
    private function storySubject(Story $story): ?array
    {
        $label = $story->getAttribute('subject_label');
        if (! is_string($label) || $label === '') {
            return null;
        }

        $type = $story->person_id !== null ? 'person'
            : ($story->album_id !== null ? 'album' : ($story->event_id !== null ? 'event' : 'photo'));
        $id = $story->person_id ?? $story->album_id ?? $story->event_id ?? $story->photo_id;

        return is_string($id) ? ['type' => $type, 'id' => $id, 'label' => $label] : null;
    }

    /** @param list<string> $albumIds
     * @return array<string, int>
     */
    private function albumCommentCounts(User $viewer, array $albumIds): array
    {
        if ($albumIds === []) {
            return [];
        }
        $visiblePhotoIds = $this->photos->visibleTo($viewer)->setEagerLoads([])->select('photos.id');

        return DB::table('photo_comments')->whereIn('album_id', $albumIds)
            ->whereIn('photo_id', $visiblePhotoIds)->whereNull('deleted_at')
            ->groupBy('album_id')->selectRaw('album_id, count(*) as aggregate')->pluck('aggregate', 'album_id')
            ->map(fn (mixed $count): int => (int) $count)->all();
    }
}
