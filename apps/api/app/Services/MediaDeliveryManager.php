<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Media\PhotoPresentationResolver;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\Photo;
use App\Models\PhotoEditPreview;
use App\Models\PhotoVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaDeliveryManager
{
    public function __construct(
        private readonly MediaDeliveryUrlSigner $signer,
        private readonly AuditRecorder $audit,
        private readonly PhotoPresentationResolver $presentations,
    ) {}

    public function canonical(MediaUpload $upload): MediaDeliveryAuthorization
    {
        if ($upload->state !== MediaUploadState::Ready || $upload->canonical_object_key === null) {
            throw new NotFoundHttpException;
        }

        return $this->authorize(
            $upload->canonical_object_key,
            $upload->canonical_mime_type ?? 'application/octet-stream',
        );
    }

    public function variant(MediaUpload $upload, MediaVariant $variant): MediaDeliveryAuthorization
    {
        if ($upload->state !== MediaUploadState::Ready
            || $variant->media_upload_id !== $upload->id
            || $variant->family_space_id !== $upload->family_space_id) {
            throw new NotFoundHttpException;
        }

        return $this->authorize($variant->object_key, $variant->mime_type);
    }

    public function original(
        MediaUpload $upload,
        User $actor,
        Request $request,
    ): MediaDeliveryAuthorization {
        if ($upload->original_object_key === null
            || ! in_array($upload->state, [
                MediaUploadState::Preserved,
                MediaUploadState::Processing,
                MediaUploadState::Ready,
                MediaUploadState::Degraded,
            ], true)) {
            throw new NotFoundHttpException;
        }

        $authorization = $this->authorize(
            $upload->original_object_key,
            $upload->detected_mime_type ?? 'application/octet-stream',
        );
        $this->audit->record(
            'original_download_authorised',
            $upload,
            $actor,
            $request,
            ['expires_at' => $authorization->expiresAt->toAtomString()],
        );

        return $authorization;
    }

    public function photoVersion(Photo $photo, PhotoVersion $version): MediaDeliveryAuthorization
    {
        if ($version->photo_id !== $photo->id || $version->family_space_id !== $photo->family_space_id) {
            throw new NotFoundHttpException;
        }

        return $this->authorize($version->derived_object_key, 'image/webp');
    }

    public function photoPresentation(Photo $photo): MediaDeliveryAuthorization
    {
        $asset = $this->presentations->resolve($photo);

        return $this->authorize($asset->objectKey, $asset->mimeType);
    }

    public function photoEditPreview(Photo $photo, PhotoEditPreview $preview): MediaDeliveryAuthorization
    {
        if ($preview->photo_id !== $photo->id || $preview->family_space_id !== $photo->family_space_id
            || $preview->expires_at->isPast()) {
            throw new NotFoundHttpException;
        }

        return $this->authorize($preview->object_key, 'image/webp');
    }

    private function authorize(string $key, string $responseContentType): MediaDeliveryAuthorization
    {
        $ttlMinutes = max(1, min(
            15,
            (int) config('media.delivery.authority_ttl_minutes'),
        ));

        return $this->signer->authorizeRead(
            $key,
            $responseContentType,
            now()->addMinutes($ttlMinutes),
            MediaSigningAudience::Browser,
        );
    }
}
