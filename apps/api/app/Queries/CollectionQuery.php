<?php

namespace App\Queries;

use App\Models\Collection;
use App\Models\Photo;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class CollectionQuery
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PhotoQuery $photos,
    ) {}

    /** @return EloquentCollection<int, Collection> */
    public function listOwnedBy(User $viewer): EloquentCollection
    {
        $visibleMembers = $this->photos->visibleTo($viewer)
            ->withoutEagerLoads()
            ->join('collection_photos', function ($join): void {
                $join->on('collection_photos.photo_id', '=', 'photos.id')
                    ->on('collection_photos.family_space_id', '=', 'photos.family_space_id');
            })
            ->whereColumn('collection_photos.collection_id', 'collections.id')
            ->whereColumn('collection_photos.family_space_id', 'collections.family_space_id');

        /** @var EloquentCollection<int, Collection> $collections */
        $collections = Collection::query()
            ->select('collections.*')
            ->selectSub(
                (clone $visibleMembers)->selectRaw('count(*)'),
                'photo_count',
            )
            ->selectSub(
                $this->orderedPreview($visibleMembers)->select('photos.id')->limit(1),
                'preview_photo_id',
            )
            ->selectSub(
                $this->orderedPreview($visibleMembers)->select('photos.media_upload_id')->limit(1),
                'preview_media_upload_id',
            )
            ->where('collections.family_space_id', $this->tenantContext->familySpace()->id)
            ->where('collections.owner_user_id', $viewer->id)
            ->orderByDesc('collections.updated_at')
            ->orderByDesc('collections.id')
            ->get();

        return $collections;
    }

    /** @param Builder<Photo> $visibleMembers
     * @return Builder<Photo>
     */
    private function orderedPreview(Builder $visibleMembers): Builder
    {
        return (clone $visibleMembers)
            ->orderBy('collection_photos.position')
            ->orderBy('collection_photos.id');
    }
}
