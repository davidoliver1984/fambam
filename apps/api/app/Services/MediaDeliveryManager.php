<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MediaDeliveryManager
{
    public function __construct(
        private readonly MediaDeliveryUrlSigner $signer,
        private readonly AuditRecorder $audit,
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
