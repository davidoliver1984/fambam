<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Jobs\ValidateMediaUpload;
use App\Media\MediaObjectStorage;
use App\Media\MediaUploadInitiation;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Storage\FamilyStorageKey;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaUploadManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{client_filename: string, client_mime_type?: string|null, upload_batch_id?: string|null} $input */
    public function initiate(
        FamilySpace $familySpace,
        User $actor,
        string $idempotencyKey,
        array $input,
        Request $request,
    ): MediaUploadInitiation {
        $fingerprint = hash('sha256', json_encode([
            'client_filename' => $input['client_filename'],
            'client_mime_type' => $input['client_mime_type'] ?? null,
            'upload_batch_id' => $input['upload_batch_id'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $existing = $this->findIdempotent($familySpace, $actor, $idempotencyKey);
        if ($existing !== null) {
            return $this->replay($existing, $fingerprint);
        }

        $context = TenantOperationContext::fromRequest($familySpace, $actor, $request);
        $upload = new MediaUpload([
            'family_space_id' => $familySpace->id,
            'user_id' => $actor->id,
            'state' => MediaUploadState::Initiated,
            'client_filename' => $input['client_filename'],
            'client_mime_type' => $input['client_mime_type'] ?? null,
            'upload_batch_id' => $input['upload_batch_id'] ?? null,
            'upload_method' => 'single',
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'correlation_id' => $context->correlationId,
            'traceparent' => $context->traceparent,
        ]);
        $upload->id = (string) Str::ulid();
        $upload->staging_object_key = FamilyStorageKey::for(
            $familySpace->id,
            "media-staging/{$upload->id}/original",
        );

        try {
            DB::transaction(function () use ($upload, $actor, $request, $context): void {
                $upload->save();
                $this->audit->record(
                    'media_upload.initiated',
                    $upload,
                    $actor,
                    $request,
                    operationContext: $context,
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->findIdempotent($familySpace, $actor, $idempotencyKey);
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $fingerprint);
        }

        return new MediaUploadInitiation($upload, $this->authorization($upload), true);
    }

    public function complete(MediaUpload $upload): MediaUpload
    {
        if ($upload->state !== MediaUploadState::Initiated) {
            return $upload;
        }

        $object = $this->storage->inspect($upload->staging_object_key);
        $maxBytes = (int) config('media.upload.max_bytes');
        if ($object === null) {
            $this->fail('The staged upload does not exist.');
        }
        if ($object->byteSize < 1 || $object->byteSize > $maxBytes) {
            $this->fail("The staged upload must be between 1 and {$maxBytes} bytes.");
        }

        $transitioned = MediaUpload::query()
            ->whereKey($upload->id)
            ->where('state', MediaUploadState::Initiated->value)
            ->update([
                'state' => MediaUploadState::Uploaded->value,
                'byte_size' => $object->byteSize,
                'uploaded_at' => now(),
                'updated_at' => now(),
            ]);

        if ($transitioned === 1) {
            $context = new TenantOperationContext(
                $upload->family_space_id,
                (int) $upload->user_id,
                $upload->correlation_id,
                $upload->traceparent,
            );
            ValidateMediaUpload::dispatch($context->toArray(), $upload->id);
        }

        return $upload->refresh();
    }

    private function findIdempotent(FamilySpace $familySpace, User $actor, string $key): ?MediaUpload
    {
        return MediaUpload::query()
            ->where('family_space_id', $familySpace->id)
            ->where('user_id', $actor->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function replay(MediaUpload $upload, string $fingerprint): MediaUploadInitiation
    {
        if (! hash_equals($upload->request_fingerprint, $fingerprint)) {
            $this->fail('This idempotency key was already used for a different upload.');
        }

        $authorization = $upload->state === MediaUploadState::Initiated
            ? $this->authorization($upload)
            : null;

        return new MediaUploadInitiation($upload, $authorization, false);
    }

    private function authorization(MediaUpload $upload): UploadAuthorization
    {
        return $this->storage->authorizeSingleWrite(
            $upload->staging_object_key,
            now()->addMinutes((int) config('media.upload.authority_ttl_minutes')),
        );
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['upload' => [$message]]);
    }
}
