<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Jobs\GenerateCanonicalMediaUpload;
use App\Media\DetectedMediaFormat;
use App\Media\ImageDecoderValidator;
use App\Media\MalwareScanner;
use App\Media\MediaFormatDetector;
use App\Media\MediaObjectStorage;
use App\Media\MediaValidationFailed;
use App\Models\MediaUpload;
use App\Models\User;
use App\Storage\FamilyStorageKey;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MediaValidationManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly MediaFormatDetector $formats,
        private readonly ImageDecoderValidator $decoder,
        private readonly MalwareScanner $malware,
        private readonly AuditRecorder $audit,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function validate(TenantOperationContext $context, string $mediaUploadId): void
    {
        $upload = $this->claim($context, $mediaUploadId);
        if ($upload === null) {
            return;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'fambam-media-');
        if ($temporaryPath === false) {
            throw new RuntimeException('A temporary media-validation file could not be created.');
        }
        chmod($temporaryPath, 0600);
        $format = null;
        $checksum = null;

        try {
            $this->storage->downloadTo($upload->staging_object_key, $temporaryPath);
            $this->assertPlausibleSize($temporaryPath);
            $format = $this->formats->detect($temporaryPath)
                ?? throw new MediaValidationFailed('unsupported_format');
            $this->decoder->validate($temporaryPath);
            $checksum = hash_file('sha256', $temporaryPath)
                ?: throw new RuntimeException('The media checksum could not be calculated.');
            $this->malware->assertClean($temporaryPath);
            $this->preserve($context, $upload, $temporaryPath, $format, $checksum);
        } catch (MediaValidationFailed $failure) {
            $this->quarantine(
                $context,
                $upload,
                $temporaryPath,
                $format,
                $checksum,
                $failure->reason,
            );
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function markFailed(TenantOperationContext $context, string $mediaUploadId): void
    {
        DB::transaction(function () use ($context, $mediaUploadId): void {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()->find($mediaUploadId);
            if ($upload === null || $upload->state !== MediaUploadState::Verifying) {
                return;
            }

            $upload->update([
                'state' => MediaUploadState::Quarantined,
                'rejection_reason' => 'validation_job_failed',
            ]);
            $this->auditRejected($context, $upload, 'validation_job_failed');
        });
    }

    private function claim(TenantOperationContext $context, string $mediaUploadId): ?MediaUpload
    {
        return DB::transaction(function () use ($context, $mediaUploadId): ?MediaUpload {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()->find($mediaUploadId);
            if ($upload === null) {
                return null;
            }
            if ($upload->state === MediaUploadState::Uploaded) {
                $upload->update(['state' => MediaUploadState::Verifying]);

                return $upload;
            }

            return $upload->state === MediaUploadState::Verifying ? $upload : null;
        });
    }

    private function assertPlausibleSize(string $path): void
    {
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > (int) config('media.upload.max_bytes')) {
            throw new MediaValidationFailed('invalid_size');
        }
    }

    private function preserve(
        TenantOperationContext $context,
        MediaUpload $upload,
        string $temporaryPath,
        DetectedMediaFormat $format,
        string $checksum,
    ): void {
        $key = FamilyStorageKey::for(
            $context->familySpaceId,
            "media/{$upload->id}/original.{$format->extension()}",
        );
        $this->storage->finalizeWriteOnce($temporaryPath, $key, $checksum);

        $transitioned = DB::transaction(function () use ($context, $upload, $format, $checksum, $key, $temporaryPath): bool {
            $this->establishContext($context);
            $locked = MediaUpload::query()->lockForUpdate()->find($upload->id);
            if ($locked === null || $locked->state !== MediaUploadState::Verifying) {
                return false;
            }

            $locked->update([
                'state' => MediaUploadState::Preserved,
                'original_object_key' => $key,
                'quarantine_object_key' => null,
                'original_sha256' => $checksum,
                'byte_size' => filesize($temporaryPath),
                'detected_mime_type' => $format->mimeType(),
                'rejection_reason' => null,
            ]);
            $actor = User::query()->find($context->actorUserId);
            $this->audit->record(
                'media_upload.original_accepted',
                $locked,
                $actor,
                metadata: [
                    'detected_mime_type' => $format->mimeType(),
                    'byte_size' => $locked->byte_size,
                    'sha256' => $checksum,
                ],
                operationContext: $context,
            );

            return true;
        });

        if ($transitioned) {
            $this->deleteStaging($upload);
            GenerateCanonicalMediaUpload::dispatch(
                $context->toArray(),
                $upload->id,
                $checksum,
            );
        }
    }

    private function quarantine(
        TenantOperationContext $context,
        MediaUpload $upload,
        string $temporaryPath,
        ?DetectedMediaFormat $format,
        ?string $checksum,
        string $reason,
    ): void {
        $storageChecksum = $checksum ?? hash_file('sha256', $temporaryPath);
        if (! is_string($storageChecksum)) {
            throw new RuntimeException('The quarantined media checksum could not be calculated.');
        }
        $extension = $format?->extension() ?? 'bin';
        $key = FamilyStorageKey::for(
            $context->familySpaceId,
            "quarantine/{$upload->id}/original.{$extension}",
        );
        $this->storage->finalizeWriteOnce($temporaryPath, $key, $storageChecksum);

        $transitioned = DB::transaction(function () use (
            $context,
            $upload,
            $format,
            $checksum,
            $key,
            $reason,
            $temporaryPath,
        ): bool {
            $this->establishContext($context);
            $locked = MediaUpload::query()->lockForUpdate()->find($upload->id);
            if ($locked === null || $locked->state !== MediaUploadState::Verifying) {
                return false;
            }

            $locked->update([
                'state' => MediaUploadState::Quarantined,
                'quarantine_object_key' => $key,
                'original_sha256' => $checksum,
                'byte_size' => filesize($temporaryPath),
                'detected_mime_type' => $format?->mimeType(),
                'rejection_reason' => $reason,
            ]);
            $this->auditRejected($context, $locked, $reason);

            return true;
        });

        if ($transitioned) {
            $this->deleteStaging($upload);
        }
    }

    private function auditRejected(TenantOperationContext $context, MediaUpload $upload, string $reason): void
    {
        $this->audit->record(
            'media_upload.original_quarantined',
            $upload,
            User::query()->find($context->actorUserId),
            metadata: [
                'reason' => $reason,
                'detected_mime_type' => $upload->detected_mime_type,
            ],
            operationContext: $context,
        );
    }

    private function deleteStaging(MediaUpload $upload): void
    {
        try {
            $this->storage->delete($upload->staging_object_key);
        } catch (Throwable $exception) {
            Log::warning('Staged media cleanup failed after durable finalisation.', [
                'media_upload_id' => $upload->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
