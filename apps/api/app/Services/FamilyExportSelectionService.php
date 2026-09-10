<?php

namespace App\Services;

use App\Enums\FamilyExportScope;
use App\Enums\MediaUploadState;
use App\Enums\PersonProposalStatus;
use App\Exports\FamilyExportSelection;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilyExport;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoPerson;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Models\SavedSearch;
use App\Models\User;
use App\Queries\AlbumQuery;
use App\Queries\FamilyEventQuery;
use App\Queries\PhotoQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class FamilyExportSelectionService
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly FamilyEventQuery $events,
    ) {}

    public function resolve(FamilyExport $export, User $requester): FamilyExportSelection
    {
        return $export->scope === FamilyExportScope::FamilySpaceFull
            ? $this->full($export)
            : $this->personal($export, $requester);
    }

    private function full(FamilyExport $export): FamilyExportSelection
    {
        $familySpaceId = $export->family_space_id;
        $photoIds = $this->ids(Photo::withTrashed()->where('family_space_id', $familySpaceId));

        return new FamilyExportSelection(
            photoIds: $photoIds,
            contextPhotoIds: [],
            albumIds: $this->ids(Album::query()->where('family_space_id', $familySpaceId)),
            eventIds: $this->ids(FamilyEvent::withTrashed()->where('family_space_id', $familySpaceId)),
            storyIds: $this->ids(PhotoStory::withTrashed()->where('family_space_id', $familySpaceId)),
            commentIds: $this->ids(PhotoComment::withTrashed()->where('family_space_id', $familySpaceId)),
            reactionIds: $this->ids(PhotoReaction::query()->where('family_space_id', $familySpaceId)),
            personIds: $this->ids(Person::withTrashed()->where('family_space_id', $familySpaceId)),
            savedSearchIds: $this->ids(SavedSearch::query()->where('family_space_id', $familySpaceId)),
            originalPhotoIds: $photoIds,
            unattachedMediaUploadIds: $this->ids(MediaUpload::query()
                ->where('family_space_id', $familySpaceId)
                ->whereIn('state', [
                    MediaUploadState::Preserved,
                    MediaUploadState::Processing,
                    MediaUploadState::Ready,
                    MediaUploadState::Degraded,
                ])
                ->whereNotNull('original_object_key')
                ->whereNotNull('original_sha256')
                ->whereDoesntHave('photo')),
        );
    }

    private function personal(FamilyExport $export, User $requester): FamilyExportSelection
    {
        $familySpaceId = $export->family_space_id;
        $visiblePhotoIds = $this->ids($this->photos->visibleTo($requester));
        $visibleAlbumIds = $this->ids($this->albums->visibleTo($requester));
        $ownedPhotoIds = $this->ids($this->photos->visibleTo($requester)->where('created_by', $requester->id));
        $ownedEventIds = $this->ids($this->events->visibleTo($requester)->where('created_by', $requester->id));
        $albumIds = $this->ids($this->albums->visibleTo($requester)->where(function (Builder $query) use ($requester, $ownedEventIds): void {
            $query->where('created_by', $requester->id);
            if ($ownedEventIds !== []) {
                $query->orWhereIn('event_id', $ownedEventIds);
            }
        }));
        $containerPhotoIds = $this->ids($this->photos->visibleTo($requester)->where(function (Builder $query) use ($ownedPhotoIds, $albumIds): void {
            $query->whereIn('id', $ownedPhotoIds);
            if ($albumIds !== []) {
                $query->orWhereHas('albums', fn (Builder $albums) => $albums->whereIn('albums.id', $albumIds));
            }
        }));

        $ownStoryIds = $this->ids(PhotoStory::query()->where('family_space_id', $familySpaceId)
            ->where('author_id', $requester->id)->whereIn('photo_id', $visiblePhotoIds));
        $ownCommentIds = $this->ids(PhotoComment::query()->where('family_space_id', $familySpaceId)
            ->where('author_id', $requester->id)->whereIn('photo_id', $visiblePhotoIds)
            ->where(fn (Builder $query) => $this->visibleConversationScope($query, $visibleAlbumIds)));
        $ownReactionIds = $this->ids(PhotoReaction::query()->where('family_space_id', $familySpaceId)
            ->where('user_id', $requester->id)->whereIn('photo_id', $visiblePhotoIds)
            ->where(fn (Builder $query) => $this->visibleConversationScope($query, $visibleAlbumIds)));
        $contextPhotoIds = collect([
            ...PhotoStory::query()->whereIn('id', $ownStoryIds)->pluck('photo_id'),
            ...PhotoComment::query()->whereIn('id', $ownCommentIds)->pluck('photo_id'),
            ...PhotoReaction::query()->whereIn('id', $ownReactionIds)->pluck('photo_id'),
        ])->unique()->diff($containerPhotoIds)->sort()->values()->all();
        $allContextIds = collect([...$containerPhotoIds, ...$contextPhotoIds])->unique()->values()->all();

        $storyIds = collect($this->ids(PhotoStory::query()->where('family_space_id', $familySpaceId)
            ->whereIn('photo_id', $containerPhotoIds)))->merge($ownStoryIds)->unique()->sort()->values()->all();
        $commentIds = collect($this->ids(PhotoComment::query()->where('family_space_id', $familySpaceId)
            ->whereIn('photo_id', $containerPhotoIds)
            ->where(fn (Builder $query) => $this->visibleConversationScope($query, $visibleAlbumIds))))->merge($ownCommentIds)->unique()->sort()->values()->all();
        $reactionIds = collect($this->ids(PhotoReaction::query()->where('family_space_id', $familySpaceId)
            ->whereIn('photo_id', $containerPhotoIds)
            ->where(fn (Builder $query) => $this->visibleConversationScope($query, $visibleAlbumIds))))->merge($ownReactionIds)->unique()->sort()->values()->all();
        $personIds = PhotoPerson::query()
            ->where('family_space_id', $familySpaceId)
            ->where('status', PersonProposalStatus::Approved)
            ->whereIn('photo_id', $allContextIds)
            ->orderBy('person_id')->pluck('person_id')->map(fn ($id): string => trim((string) $id))->all();
        $originalPhotoIds = Photo::query()->whereIn('id', $containerPhotoIds)->with('mediaUpload')->get()
            ->filter(fn (Photo $photo): bool => $photo->mediaUpload !== null
                && Gate::forUser($requester)->allows('downloadOriginal', $photo->mediaUpload))
            ->pluck('id')->sort()->values()->all();

        return new FamilyExportSelection(
            photoIds: $containerPhotoIds,
            contextPhotoIds: $contextPhotoIds,
            albumIds: $albumIds,
            eventIds: $ownedEventIds,
            storyIds: $storyIds,
            commentIds: $commentIds,
            reactionIds: $reactionIds,
            personIds: $personIds,
            savedSearchIds: $this->ids(SavedSearch::query()->where('family_space_id', $familySpaceId)->where('created_by', $requester->id)),
            originalPhotoIds: $originalPhotoIds,
            unattachedMediaUploadIds: [],
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<string>
     */
    private function ids(Builder $query): array
    {
        return $query->orderBy($query->getModel()->qualifyColumn('id'))->pluck('id')->map(fn ($id): string => trim((string) $id))->all();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $visibleAlbumIds
     */
    private function visibleConversationScope(Builder $query, array $visibleAlbumIds): void
    {
        $query->whereNull('album_id');
        if ($visibleAlbumIds !== []) {
            $query->orWhereIn('album_id', $visibleAlbumIds);
        }
    }
}
