<?php

namespace App\Http\Controllers;

use App\Enums\MediaVariantTransform;
use App\Http\Requests\InitiateMediaUploadRequest;
use App\Media\MediaDeliveryAuthorization;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\User;
use App\Services\MediaDeliveryManager;
use App\Services\MediaUploadManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MediaUploadController extends Controller
{
    public function __construct(
        private readonly MediaUploadManager $uploads,
        private readonly MediaDeliveryManager $delivery,
    ) {}

    public function store(FamilySpace $familySpace, InitiateMediaUploadRequest $request): JsonResponse
    {
        Gate::authorize('create', MediaUpload::class);
        /** @var User $actor */
        $actor = $request->user();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        abort_if($idempotencyKey === '' || strlen($idempotencyKey) > 100, 422, 'A valid Idempotency-Key header is required.');

        $result = $this->uploads->initiate(
            $familySpace,
            $actor,
            $idempotencyKey,
            $request->validated(),
            $request,
        );

        return response()->json(
            ['data' => $this->payload($result->upload, $result->authorization)],
            $result->created ? 201 : 200,
        );
    }

    public function complete(FamilySpace $familySpace, string $mediaUpload, Request $request): JsonResponse
    {
        $upload = MediaUpload::query()
            ->where('family_space_id', $familySpace->id)
            ->findOrFail($mediaUpload);
        Gate::authorize('complete', $upload);

        return response()->json(['data' => $this->payload($this->uploads->complete($upload))]);
    }

    public function canonical(FamilySpace $familySpace, string $mediaUpload): JsonResponse
    {
        $upload = $this->upload($familySpace, $mediaUpload);
        Gate::authorize('view', $upload);

        return response()->json([
            'data' => $this->deliveryPayload($this->delivery->canonical($upload), 'canonical'),
        ]);
    }

    public function variant(
        FamilySpace $familySpace,
        string $mediaUpload,
        string $transform,
    ): JsonResponse {
        $upload = $this->upload($familySpace, $mediaUpload);
        Gate::authorize('view', $upload);
        $transformName = MediaVariantTransform::tryFrom($transform);
        abort_if($transformName === null, 404);
        $variant = MediaVariant::query()
            ->where('family_space_id', $familySpace->id)
            ->where('media_upload_id', $upload->id)
            ->where('transform_name', $transformName->value)
            ->where('processing_version', (int) config('media.processing.variant_processing_version'))
            ->firstOrFail();

        return response()->json([
            'data' => $this->deliveryPayload(
                $this->delivery->variant($upload, $variant),
                'variant',
                $variant,
            ),
        ]);
    }

    public function original(FamilySpace $familySpace, string $mediaUpload, Request $request): JsonResponse
    {
        $upload = $this->upload($familySpace, $mediaUpload);
        Gate::authorize('downloadOriginal', $upload);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->deliveryPayload($this->delivery->original($upload, $actor, $request), 'original'),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(MediaUpload $upload, ?UploadAuthorization $authorization = null): array
    {
        return [
            'id' => $upload->id,
            'state' => $upload->state->value,
            'client_filename' => $upload->client_filename,
            'byte_size' => $upload->byte_size,
            'uploaded_at' => $upload->uploaded_at?->toAtomString(),
            'upload_authorization' => $authorization === null ? null : [
                'url' => $authorization->url,
                'method' => 'PUT',
                'headers' => $authorization->headers,
                'expires_at' => $authorization->expiresAt->toAtomString(),
            ],
        ];
    }

    private function upload(FamilySpace $familySpace, string $mediaUpload): MediaUpload
    {
        return MediaUpload::query()
            ->where('family_space_id', $familySpace->id)
            ->findOrFail($mediaUpload);
    }

    /** @return array<string, mixed> */
    private function deliveryPayload(
        MediaDeliveryAuthorization $authorization,
        string $asset,
        ?MediaVariant $variant = null,
    ): array {
        return [
            'asset' => $asset,
            'transform_name' => $variant?->transform_name->value,
            'processing_version' => $variant?->processing_version,
            'url' => $authorization->url,
            'method' => 'GET',
            'expires_at' => $authorization->expiresAt->toAtomString(),
        ];
    }
}
