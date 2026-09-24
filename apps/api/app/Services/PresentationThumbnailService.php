<?php

namespace App\Services;

use App\Enums\MediaVariantTransform;
use App\Models\MediaVariant;
use App\Models\User;
use App\Queries\PhotoQuery;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PresentationThumbnailService
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly TenantContext $tenantContext,
        private readonly Container $container,
    ) {}

    /**
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function forPeople(array $personIds, User $viewer): array
    {
        $personIds = array_values(array_unique(array_filter($personIds)));
        if ($personIds === []) {
            return [];
        }

        $ranked = $this->photos->visibleTo($viewer)->setEagerLoads([])
            ->join('photo_people', function ($join): void {
                $join->on('photo_people.photo_id', '=', 'photos.id')
                    ->on('photo_people.family_space_id', '=', 'photos.family_space_id');
            })
            ->whereIn('photo_people.person_id', $personIds)
            ->where('photo_people.status', 'approved')
            ->select(['photo_people.person_id', 'photos.media_upload_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY photo_people.person_id ORDER BY CASE WHEN photos.historical_date IS NULL THEN 1 ELSE 0 END, photos.historical_date, photos.id) AS presentation_rank');
        $candidates = DB::query()->fromSub($ranked->toBase(), 'person_presentation_photos')
            ->where('presentation_rank', 1)->get();
        $urls = $this->forMediaUploads($candidates->pluck('media_upload_id')->all());

        return $candidates->mapWithKeys(fn (object $row): array => isset($urls[$row->media_upload_id])
            ? [(string) $row->person_id => $urls[$row->media_upload_id]] : [])->all();
    }

    /**
     * The caller must authorize the owning Photo/Album/Story before supplying IDs.
     *
     * @param  list<string>  $mediaUploadIds
     * @return array<string, string>
     */
    public function forMediaUploads(array $mediaUploadIds): array
    {
        $mediaUploadIds = array_values(array_unique(array_filter($mediaUploadIds)));
        if ($mediaUploadIds === []) {
            return [];
        }

        $variants = MediaVariant::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->whereIn('media_upload_id', $mediaUploadIds)
            ->where('transform_name', MediaVariantTransform::Thumbnail->value)
            ->where('processing_version', (int) config('media.processing.variant_processing_version'))
            ->with('mediaUpload')
            ->get()
            ->keyBy('media_upload_id');
        $urls = [];
        if ($variants->isEmpty()) {
            return $urls;
        }
        $delivery = $this->container->make(MediaDeliveryManager::class);
        foreach ($variants as $mediaUploadId => $variant) {
            try {
                $urls[(string) $mediaUploadId] = $delivery->variant($variant->mediaUpload, $variant)->url;
            } catch (NotFoundHttpException) {
                // Missing/degraded presentation media uses the frontend fallback.
            }
        }

        return $urls;
    }
}
