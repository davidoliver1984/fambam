<?php

namespace App\Queries;

use App\Collections\CollectionListCriteria;
use App\Enums\CollectionPurpose;
use App\Models\Collection;
use App\Models\Photo;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

final class CollectionQuery
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PhotoQuery $photos,
    ) {}

    /** @return EloquentCollection<int, Collection> */
    public function listOwnedBy(User $viewer, CollectionListCriteria $criteria = new CollectionListCriteria): EloquentCollection
    {
        $visibleMembers = $this->photos->visibleTo($viewer)
            ->withoutEagerLoads()
            ->join('collection_photos', function ($join): void {
                $join->on('collection_photos.photo_id', '=', 'photos.id')
                    ->on('collection_photos.family_space_id', '=', 'photos.family_space_id');
            })
            ->whereColumn('collection_photos.collection_id', 'collections.id')
            ->whereColumn('collection_photos.family_space_id', 'collections.family_space_id');

        $query = Collection::query()
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
            ->where('collections.owner_user_id', $viewer->id);

        if ($criteria->term !== null) {
            $term = '%'.Str::lower($criteria->term).'%';
            $query->where(function (Builder $matches) use ($term): void {
                $matches->whereRaw('LOWER(collections.name) LIKE ?', [$term])
                    ->orWhereRaw("LOWER(COALESCE(collections.description, '')) LIKE ?", [$term]);
            });
        }
        if ($criteria->collectionId !== null) {
            $query->where('collections.id', $criteria->collectionId);
        }
        if ($criteria->purposes !== []) {
            $query->whereIn('collections.purpose', array_map(
                fn (CollectionPurpose $purpose): string => $purpose->value,
                $criteria->purposes,
            ));
        }
        if ($criteria->sort === 'name') {
            $query->orderByRaw('LOWER(collections.name) ASC')->orderBy('collections.id');
        } else {
            $query->orderByDesc('collections.updated_at')->orderByDesc('collections.id');
        }

        /** @var EloquentCollection<int, Collection> $collections */
        $collections = $query->get();

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
