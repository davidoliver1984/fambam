<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Media\MediaObjectStorage;
use App\Media\PerceptualHashDistance;
use App\Media\PerceptualHasher;
use App\Models\DuplicateCandidate;
use App\Models\DuplicateDecision;
use App\Models\MediaUpload;
use App\Models\PerceptualHash;
use App\Models\Photo;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PerceptualSimilarityManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly PerceptualHasher $hasher,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function generate(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $canonicalSha256,
        string $algorithm,
        int $processingVersion,
    ): void {
        if ($algorithm !== config('media.processing.perceptual_algorithm')
            || $processingVersion !== config('media.processing.perceptual_processing_version')) {
            return;
        }

        $upload = $this->claim($context, $mediaUploadId, $canonicalSha256);
        if ($upload === null) {
            return;
        }

        $canonicalPath = tempnam(sys_get_temp_dir(), 'fambam-perceptual-source-');
        if ($canonicalPath === false) {
            throw new \RuntimeException('A temporary perceptual-hash source file could not be created.');
        }
        chmod($canonicalPath, 0600);

        try {
            $this->storage->downloadTo((string) $upload->canonical_object_key, $canonicalPath);
            $actualChecksum = hash_file('sha256', $canonicalPath);
            if ($actualChecksum === false || ! hash_equals($canonicalSha256, $actualChecksum)) {
                throw new \RuntimeException('The canonical asset failed its perceptual-hash integrity check.');
            }

            $hash = $this->hasher->hash($canonicalPath);
            $this->persistAndMatch($context, $upload, $canonicalSha256, $algorithm, $processingVersion, $hash);
        } finally {
            @unlink($canonicalPath);
        }
    }

    private function claim(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $canonicalSha256,
    ): ?MediaUpload {
        return DB::transaction(function () use ($context, $mediaUploadId, $canonicalSha256): ?MediaUpload {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()
                ->where('family_space_id', $context->familySpaceId)
                ->find($mediaUploadId);
            if ($upload === null
                || $upload->state !== MediaUploadState::Ready
                || ! hash_equals($canonicalSha256, $upload->canonical_sha256 ?? '')
                || $upload->canonical_object_key === null
                || ! Photo::query()->where('media_upload_id', $upload->id)->exists()) {
                return null;
            }

            return $upload;
        });
    }

    private function persistAndMatch(
        TenantOperationContext $context,
        MediaUpload $upload,
        string $canonicalSha256,
        string $algorithm,
        int $processingVersion,
        string $hash,
    ): void {
        DB::transaction(function () use (
            $context,
            $upload,
            $canonicalSha256,
            $algorithm,
            $processingVersion,
            $hash,
        ): void {
            $this->establishContext($context);
            $locked = MediaUpload::query()->lockForUpdate()
                ->where('family_space_id', $context->familySpaceId)
                ->find($upload->id);
            $photo = Photo::query()
                ->where('family_space_id', $context->familySpaceId)
                ->where('media_upload_id', $upload->id)
                ->first();
            if ($locked === null
                || $photo === null
                || $locked->state !== MediaUploadState::Ready
                || ! hash_equals($canonicalSha256, $locked->canonical_sha256 ?? '')) {
                return;
            }

            PerceptualHash::query()->updateOrCreate([
                'media_upload_id' => $locked->id,
                'algorithm' => $algorithm,
                'processing_version' => $processingVersion,
            ], [
                'family_space_id' => $context->familySpaceId,
                'hash_value' => $hash,
            ]);

            $matches = PerceptualHash::query()
                ->with(['mediaUpload.photo'])
                ->where('family_space_id', $context->familySpaceId)
                ->where('algorithm', $algorithm)
                ->where('processing_version', $processingVersion)
                ->where('media_upload_id', '!=', $locked->id)
                ->whereHas('mediaUpload.photo', fn ($query) => $query->whereNull('photos.deleted_at'))
                ->get();

            foreach ($matches as $match) {
                $matchUpload = $match->mediaUpload;
                $matchPhoto = $matchUpload->photo;
                if ($matchPhoto === null
                    || ($locked->original_sha256 !== null
                        && $matchUpload->original_sha256 !== null
                        && hash_equals($locked->original_sha256, $matchUpload->original_sha256))) {
                    continue;
                }

                $score = PerceptualHashDistance::hamming($hash, $match->hash_value);
                if ($score > (int) config('media.processing.perceptual_hamming_threshold')) {
                    continue;
                }

                [$low, $high] = $this->orderedPair($photo->id, $matchPhoto->id);
                if (DuplicateDecision::query()
                    ->where('family_space_id', $context->familySpaceId)
                    ->where('photo_low_id', $low)
                    ->where('photo_high_id', $high)
                    ->whereNull('reopened_at')
                    ->exists()) {
                    continue;
                }

                DuplicateCandidate::query()->upsert([[
                    'id' => (string) Str::ulid(),
                    'family_space_id' => $context->familySpaceId,
                    'photo_id' => $low,
                    'candidate_photo_id' => $high,
                    'source' => 'perceptual',
                    'status' => 'pending',
                    'algorithm' => $algorithm,
                    'processing_version' => $processingVersion,
                    'score' => $score,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]], ['family_space_id', 'photo_id', 'candidate_photo_id', 'source'], [
                    'status',
                    'algorithm',
                    'processing_version',
                    'score',
                    'updated_at',
                ]);
            }
        });
    }

    /** @return array{string, string} */
    private function orderedPair(string $first, string $second): array
    {
        return strcmp($first, $second) < 0 ? [$first, $second] : [$second, $first];
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
