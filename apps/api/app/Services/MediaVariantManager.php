<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Media\GeneratedMediaVariant;
use App\Media\MediaObjectStorage;
use App\Media\PresentationVariantGenerator;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Storage\FamilyStorageKey;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;

class MediaVariantManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly PresentationVariantGenerator $generator,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function generate(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $canonicalSha256,
        int $processingVersion,
    ): void {
        $upload = $this->claim($context, $mediaUploadId, $canonicalSha256);
        if ($upload === null) {
            return;
        }

        $canonicalPath = tempnam(sys_get_temp_dir(), 'fambam-canonical-source-');
        if ($canonicalPath === false) {
            throw new \RuntimeException('A temporary canonical source file could not be created.');
        }
        chmod($canonicalPath, 0600);
        $generated = [];

        try {
            $this->storage->downloadTo((string) $upload->canonical_object_key, $canonicalPath);
            $actualChecksum = hash_file('sha256', $canonicalPath);
            if ($actualChecksum === false || ! hash_equals($canonicalSha256, $actualChecksum)) {
                throw new \RuntimeException('The canonical asset failed its variant integrity check.');
            }

            foreach (MediaVariantTransform::cases() as $transform) {
                $variant = $this->generator->generate($canonicalPath, $transform);
                $key = FamilyStorageKey::for(
                    $context->familySpaceId,
                    "media/{$upload->id}/variants/{$transform->value}.v{$processingVersion}.{$variant->extension}",
                );
                $generated[] = [$transform, $variant, $key];
                $this->storage->finalizeWriteOnce($variant->path, $key, $variant->sha256);
            }

            $this->persist($context, $upload, $canonicalSha256, $processingVersion, $generated);
        } finally {
            @unlink($canonicalPath);
            foreach ($generated as [, $variant]) {
                @unlink($variant->path);
            }
        }
    }

    public function markDegraded(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $canonicalSha256,
    ): void {
        DB::transaction(function () use ($context, $mediaUploadId, $canonicalSha256): void {
            $this->establishContext($context);
            MediaUpload::query()
                ->whereKey($mediaUploadId)
                ->where('state', MediaUploadState::Processing->value)
                ->where('canonical_sha256', $canonicalSha256)
                ->update(['state' => MediaUploadState::Degraded->value]);
        });
    }

    private function claim(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $canonicalSha256,
    ): ?MediaUpload {
        return DB::transaction(function () use ($context, $mediaUploadId, $canonicalSha256): ?MediaUpload {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()->find($mediaUploadId);
            if ($upload === null
                || ! in_array($upload->state, [MediaUploadState::Processing, MediaUploadState::Ready], true)
                || ! hash_equals($canonicalSha256, $upload->canonical_sha256 ?? '')
                || $upload->canonical_object_key === null) {
                return null;
            }

            return $upload;
        });
    }

    /**
     * @param  list<array{MediaVariantTransform, GeneratedMediaVariant, string}>  $generated
     */
    private function persist(
        TenantOperationContext $context,
        MediaUpload $upload,
        string $canonicalSha256,
        int $processingVersion,
        array $generated,
    ): void {
        DB::transaction(function () use (
            $context,
            $upload,
            $canonicalSha256,
            $processingVersion,
            $generated,
        ): void {
            $this->establishContext($context);
            $locked = MediaUpload::query()->lockForUpdate()->find($upload->id);
            if ($locked === null
                || ! in_array($locked->state, [MediaUploadState::Processing, MediaUploadState::Ready], true)
                || ! hash_equals($canonicalSha256, $locked->canonical_sha256 ?? '')) {
                return;
            }

            foreach ($generated as [$transform, $variant, $key]) {
                MediaVariant::query()->updateOrCreate(
                    [
                        'media_upload_id' => $locked->id,
                        'transform_name' => $transform->value,
                        'processing_version' => $processingVersion,
                    ],
                    [
                        'family_space_id' => $context->familySpaceId,
                        'object_key' => $key,
                        'mime_type' => $variant->mimeType,
                        'sha256' => $variant->sha256,
                        'pixel_width' => $variant->width,
                        'pixel_height' => $variant->height,
                        'byte_size' => $variant->byteSize,
                    ],
                );
            }

            if ($locked->state === MediaUploadState::Processing) {
                $locked->update(['state' => MediaUploadState::Ready]);
            }
        });
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
