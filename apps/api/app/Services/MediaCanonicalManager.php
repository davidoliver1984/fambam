<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Jobs\DispatchFaceAnalysis;
use App\Jobs\GeneratePresentationMediaVariants;
use App\Media\CanonicalImageGenerator;
use App\Media\ExtractedMediaMetadata;
use App\Media\GeneratedCanonical;
use App\Media\MediaMetadataExtractor;
use App\Media\MediaObjectStorage;
use App\Models\MediaUpload;
use App\Storage\FamilyStorageKey;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;

class MediaCanonicalManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly MediaMetadataExtractor $metadataExtractor,
        private readonly CanonicalImageGenerator $canonicalGenerator,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function generate(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $sourceSha256,
    ): void {
        $upload = $this->claim($context, $mediaUploadId, $sourceSha256);
        if ($upload === null) {
            return;
        }

        $originalPath = tempnam(sys_get_temp_dir(), 'fambam-original-');
        if ($originalPath === false) {
            throw new \RuntimeException('A temporary original file could not be created.');
        }
        chmod($originalPath, 0600);
        $canonical = null;

        try {
            $this->storage->downloadTo((string) $upload->original_object_key, $originalPath);
            $actualChecksum = hash_file('sha256', $originalPath);
            if ($actualChecksum === false || ! hash_equals($sourceSha256, $actualChecksum)) {
                throw new \RuntimeException('The preserved original failed its processing integrity check.');
            }

            $metadata = $this->metadataExtractor->extract($originalPath);
            $canonical = $this->canonicalGenerator->generate($originalPath);
            $key = FamilyStorageKey::for(
                $context->familySpaceId,
                "media/{$upload->id}/canonical.{$canonical->extension}",
            );
            $this->storage->finalizeWriteOnce($canonical->path, $key, $canonical->sha256);
            if ($this->persist($context, $upload, $sourceSha256, $metadata, $canonical, $key)) {
                DispatchFaceAnalysis::dispatch(
                    $context->toArray(),
                    $upload->id,
                    $canonical->sha256,
                );
                GeneratePresentationMediaVariants::dispatch(
                    $context->toArray(),
                    $upload->id,
                    $canonical->sha256,
                    (int) config('media.processing.variant_processing_version'),
                );
            }
        } finally {
            @unlink($originalPath);
            if ($canonical instanceof GeneratedCanonical) {
                @unlink($canonical->path);
            }
        }
    }

    private function claim(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $sourceSha256,
    ): ?MediaUpload {
        return DB::transaction(function () use ($context, $mediaUploadId, $sourceSha256): ?MediaUpload {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()->find($mediaUploadId);
            if ($upload === null
                || $upload->state !== MediaUploadState::Preserved
                || ! hash_equals($sourceSha256, $upload->original_sha256 ?? '')
                || $upload->original_object_key === null) {
                return null;
            }

            return $upload;
        });
    }

    private function persist(
        TenantOperationContext $context,
        MediaUpload $upload,
        string $sourceSha256,
        ExtractedMediaMetadata $metadata,
        GeneratedCanonical $canonical,
        string $key,
    ): bool {
        return DB::transaction(function () use ($context, $upload, $sourceSha256, $metadata, $canonical, $key): bool {
            $this->establishContext($context);
            $locked = MediaUpload::query()->lockForUpdate()->find($upload->id);
            if ($locked === null
                || $locked->state !== MediaUploadState::Preserved
                || ! hash_equals($sourceSha256, $locked->original_sha256 ?? '')) {
                return false;
            }

            $locked->update([
                'state' => MediaUploadState::Processing,
                'pixel_width' => $metadata->width,
                'pixel_height' => $metadata->height,
                'original_orientation' => $metadata->orientation,
                'camera_make' => $metadata->cameraMake,
                'camera_model' => $metadata->cameraModel,
                'exif_capture_timestamp' => $metadata->captureTimestamp,
                'gps_latitude' => $metadata->gpsLatitude,
                'gps_longitude' => $metadata->gpsLongitude,
                'original_exif_base64' => $metadata->rawExif === null ? null : base64_encode($metadata->rawExif),
                'original_icc_profile_base64' => $metadata->rawIccProfile === null
                    ? null
                    : base64_encode($metadata->rawIccProfile),
                'canonical_object_key' => $key,
                'canonical_mime_type' => $canonical->mimeType,
                'canonical_sha256' => $canonical->sha256,
            ]);

            return true;
        });
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
