<?php

namespace App\Services;

use App\Enums\MembershipState;
use App\Enums\NotificationCategory;
use App\Models\AlbumPhoto;
use App\Models\ContributionGroup;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\LoveNotificationGroupActor;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoPerson;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use App\Stories\RichTextDocument;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class NotificationPresentationBuilder
{
    public function __construct(
        private readonly PresentationThumbnailService $thumbnails,
        private readonly RichTextDocument $documents,
    ) {}

    /**
     * @param  EloquentCollection<int, FamilyNotification>  $notifications
     * @return array<string, array<string, mixed>>
     */
    public function build(FamilySpace $familySpace, User $viewer, EloquentCollection $notifications): array
    {
        if ($notifications->isEmpty()) {
            return [];
        }

        [$actorIds, $details, $contributionCounts] = $this->sourcePresentation($familySpace, $notifications);
        $allActorIds = collect($actorIds)->flatten()->filter()->unique()->values();
        $activeActorIds = FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id)
            ->where('state', MembershipState::Active->value)
            ->whereIn('user_id', $allActorIds)
            ->pluck('user_id');
        $actors = User::query()->whereIn('id', $activeActorIds)->whereNull('revoked_at')->get()->keyBy('id');
        $links = PersonAccountLink::query()->where('family_space_id', $familySpace->id)
            ->whereIn('user_id', $activeActorIds)->with('person')->get()->keyBy('user_id');
        $visibleActorLinks = $links->filter(fn (PersonAccountLink $link): bool => $link->person !== null
            && Gate::forUser($viewer)->allows('view', $link->person));
        $portraitUrls = $this->thumbnails->forPeople(
            $visibleActorLinks->pluck('person_id')->values()->all(),
            $viewer,
        );

        $targetPhotos = [];
        foreach ($notifications as $notification) {
            $photo = $this->thumbnailPhoto($notification, $viewer);
            if ($photo !== null) {
                $targetPhotos[$notification->id] = $photo;
            }
        }
        $targetUrls = $this->thumbnails->forMediaUploads(array_values(array_map(
            fn (Photo $photo): string => $photo->media_upload_id,
            $targetPhotos,
        )));

        $result = [];
        foreach ($notifications as $notification) {
            $noticeActorIds = array_values(array_filter(
                $actorIds[$notification->id] ?? [],
                fn (int $id): bool => $actors->has($id),
            ));
            $primaryActor = $noticeActorIds === [] ? null : $actors->get($noticeActorIds[0]);
            $link = $primaryActor === null ? null : $visibleActorLinks->get($primaryActor->id);
            $target = $this->target($notification);
            $targetLabel = $this->targetLabel($notification);
            $actor = $primaryActor === null ? null : [
                'person_id' => $link?->person_id,
                'display_name' => $primaryActor->name,
                'initials' => $this->initials($primaryActor->name),
                'portrait_thumbnail_url' => $link === null ? null : ($portraitUrls[$link->person_id] ?? null),
            ];
            $targetPhoto = $targetPhotos[$notification->id] ?? null;

            $result[$notification->id] = [
                'actor' => $actor,
                'headline' => $this->headline(
                    $notification,
                    $primaryActor?->name,
                    count($noticeActorIds),
                    $contributionCounts[$notification->id] ?? 1,
                ),
                'detail' => $details[$notification->id] ?? null,
                'target_label' => $targetLabel,
                'thumbnail_url' => $targetPhoto === null ? null : ($targetUrls[$targetPhoto->media_upload_id] ?? null),
                'target' => $target,
            ];
        }

        return $result;
    }

    /**
     * @param  EloquentCollection<int, FamilyNotification>  $notifications
     * @return array{array<string, list<int>>, array<string, string>, array<string, int>}
     */
    private function sourcePresentation(FamilySpace $familySpace, EloquentCollection $notifications): array
    {
        $sourceIds = $notifications->pluck('source_action_id')->filter()->unique()->values();
        $photoComments = PhotoComment::query()->where('family_space_id', $familySpace->id)
            ->whereIn('id', $sourceIds)->get()->keyBy('id');
        $storyComments = StoryComment::query()->where('family_space_id', $familySpace->id)
            ->whereIn('id', $sourceIds)->get()->keyBy('id');
        $photoPeople = PhotoPerson::query()->where('family_space_id', $familySpace->id)
            ->whereIn('id', $sourceIds)->get()->keyBy('id');
        $contributionGroups = ContributionGroup::query()->where('family_space_id', $familySpace->id)
            ->whereIn('id', $sourceIds)->get()->keyBy('id');
        $albumPhotos = AlbumPhoto::query()->where('family_space_id', $familySpace->id)
            ->whereIn('id', $sourceIds)->get()->keyBy('id');
        $loveActors = LoveNotificationGroupActor::query()->where('family_space_id', $familySpace->id)
            ->whereIn('group_id', $sourceIds)
            ->orderBy('actor_user_id')->get()->groupBy('group_id');

        $groupCounts = collect();
        if ($contributionGroups->isNotEmpty()) {
            $groupCounts = DB::table('album_photos')
                ->where('album_photos.family_space_id', $familySpace->id)
                ->join('photos', function ($join): void {
                    $join->on('photos.id', '=', 'album_photos.photo_id')
                        ->on('photos.family_space_id', '=', 'album_photos.family_space_id');
                })
                ->join('media_uploads', function ($join): void {
                    $join->on('media_uploads.id', '=', 'photos.media_upload_id')
                        ->on('media_uploads.family_space_id', '=', 'photos.family_space_id');
                })
                ->whereIn('album_photos.album_id', $contributionGroups->pluck('album_id'))
                ->whereIn('media_uploads.user_id', $contributionGroups->pluck('actor_user_id'))
                ->whereIn('media_uploads.upload_batch_id', $contributionGroups->pluck('upload_batch_id'))
                ->whereNull('photos.deleted_at')
                ->groupBy(['album_photos.album_id', 'media_uploads.user_id', 'media_uploads.upload_batch_id'])
                ->get(['album_photos.album_id', 'media_uploads.user_id', 'media_uploads.upload_batch_id', DB::raw('COUNT(*) AS aggregate')])
                ->keyBy(fn (object $row): string => $row->album_id.':'.$row->user_id.':'.$row->upload_batch_id);
        }

        $actorIds = [];
        $details = [];
        $counts = [];
        foreach ($notifications as $notification) {
            $sourceId = $notification->source_action_id;
            $actorIds[$notification->id] = match ($notification->category) {
                NotificationCategory::Comment => $photoComments->has($sourceId)
                    ? [(int) $photoComments->get($sourceId)->author_id]
                    : ($storyComments->has($sourceId) ? [(int) $storyComments->get($sourceId)->author_id] : []),
                NotificationCategory::Contribution => $contributionGroups->has($sourceId)
                    ? [(int) $contributionGroups->get($sourceId)->actor_user_id]
                    : ($albumPhotos->has($sourceId) ? [(int) $albumPhotos->get($sourceId)->added_by] : []),
                NotificationCategory::Story => $notification->story?->author_id === null
                    ? [] : [(int) $notification->story->author_id],
                NotificationCategory::Identity => $photoPeople->has($sourceId)
                    ? [(int) ($photoPeople->get($sourceId)->resolved_by ?? $photoPeople->get($sourceId)->proposed_by)] : [],
                NotificationCategory::Love => $loveActors->get($sourceId, collect())->pluck('actor_user_id')
                    ->map(fn ($id): int => (int) $id)->values()->all(),
                NotificationCategory::Attendance, NotificationCategory::Export => [],
            };
            if ($photoComments->has($sourceId)) {
                $details[$notification->id] = Str::limit(trim($photoComments->get($sourceId)->body_plain_text), 120);
            } elseif ($storyComments->has($sourceId)) {
                $details[$notification->id] = Str::limit(trim($this->documents->plainText(
                    $storyComments->get($sourceId)->body,
                )), 120);
            }
            if ($contributionGroups->has($sourceId)) {
                $group = $contributionGroups->get($sourceId);
                $key = $group->album_id.':'.$group->actor_user_id.':'.$group->upload_batch_id;
                $counts[$notification->id] = $groupCounts->has($key)
                    ? (int) $groupCounts->get($key)->aggregate : 1;
            }
        }

        return [$actorIds, $details, $counts];
    }

    private function thumbnailPhoto(FamilyNotification $notification, User $viewer): ?Photo
    {
        $photo = $this->relatedPhoto($notification);
        $story = $this->relatedStory($notification);
        if ($photo === null && $story !== null) {
            $photo = $story->photo;
        }
        if ($photo === null && $notification->album !== null) {
            $photo = $notification->album->coverPhoto;
        }

        return $photo !== null && Gate::forUser($viewer)->allows('view', $photo) ? $photo : null;
    }

    /** @return array{type: string, id: string}|null */
    private function target(FamilyNotification $notification): ?array
    {
        [$type, $id] = match ($notification->category) {
            NotificationCategory::Comment => $notification->story_id !== null
                ? ['story', $notification->story_id] : ['photo', $notification->photo_id],
            NotificationCategory::Contribution => ['album', $notification->album_id],
            NotificationCategory::Story => ['story', $notification->story_id],
            NotificationCategory::Identity => ['photo', $notification->photo_id],
            NotificationCategory::Export => ['family_export', $notification->family_export_id],
            NotificationCategory::Attendance => ['event', $notification->event_id],
            NotificationCategory::Love => match (true) {
                $notification->photo_id !== null => ['photo', $notification->photo_id],
                $notification->album_id !== null => ['album', $notification->album_id],
                $notification->event_id !== null => ['event', $notification->event_id],
                default => ['story', $notification->story_id],
            },
        };

        return $id === null ? null : ['type' => $type, 'id' => $id];
    }

    private function targetLabel(FamilyNotification $notification): ?string
    {
        $photo = $this->relatedPhoto($notification);

        return match ($this->target($notification)['type'] ?? null) {
            'photo' => $photo === null ? 'Photograph' : ($photo->caption ?? 'Photograph'),
            'album' => $notification->album?->name,
            'story' => $notification->story === null
                ? null : Str::limit(trim($notification->story->body_plain_text), 80),
            'event' => $notification->event?->name,
            'family_export' => 'Family export',
            default => null,
        };
    }

    private function relatedPhoto(FamilyNotification $notification): ?Photo
    {
        $photo = $notification->getRelation('photo');

        return $photo instanceof Photo ? $photo : null;
    }

    private function relatedStory(FamilyNotification $notification): ?Story
    {
        $story = $notification->getRelation('story');

        return $story instanceof Story ? $story : null;
    }

    private function headline(
        FamilyNotification $notification,
        ?string $actorName,
        int $actorCount,
        int $contributionCount,
    ): string {
        $actor = $actorName ?? 'Someone';

        return match ($notification->category) {
            NotificationCategory::Comment => $actor.' commented on your '.($notification->story_id === null ? 'photograph' : 'story'),
            NotificationCategory::Contribution => $actor.' added '.$contributionCount.' '.Str::plural('photograph', $contributionCount),
            NotificationCategory::Story => $actor.' added a story',
            NotificationCategory::Identity => $actor.' confirmed your identity in a photograph',
            NotificationCategory::Export => 'Your Fambam export status changed',
            NotificationCategory::Attendance => 'Someone responded to an Event invitation',
            NotificationCategory::Love => $actor.($actorCount > 1 ? ' and '.($actorCount - 1).' others' : '')
                .' loved your '.ucfirst($this->target($notification)['type'] ?? 'memory'),
        };
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }
}
