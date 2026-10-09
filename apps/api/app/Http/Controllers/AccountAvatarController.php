<?php

namespace App\Http\Controllers;

use App\Enums\MediaUploadState;
use App\Http\Requests\InitiateMediaUploadRequest;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\MediaUploadManager;
use App\Tenancy\DatabaseTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AccountAvatarController extends Controller
{
    public function initiate(
        FamilySpace $familySpace,
        InitiateMediaUploadRequest $request,
        MediaUploadManager $uploads,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        abort_if($idempotencyKey === '' || strlen($idempotencyKey) > 100, 422, 'A valid Idempotency-Key header is required.');
        $input = $request->safe()->only(['client_filename', 'client_mime_type']);
        $input['purpose'] = 'account_avatar';
        $result = $uploads->initiate($familySpace, $user, $idempotencyKey, $input, $request);

        return response()->json(
            ['data' => $this->uploadPayload($result->upload, $result->authorization)],
            $result->created ? 201 : 200,
        );
    }

    public function update(
        Request $request,
        AuditRecorder $audit,
        DatabaseTenantContext $databaseTenantContext,
    ): JsonResponse {
        $validated = $request->validate(['media_upload_id' => ['required', 'string', 'ulid']]);
        /** @var User $user */
        $user = $request->user();
        /** @var MediaUpload $upload */
        $upload = MediaUpload::query()
            ->whereKey($validated['media_upload_id'])
            ->where('user_id', $user->id)
            ->where('purpose', 'account_avatar')
            ->where('state', MediaUploadState::Ready->value)
            ->firstOrFail();

        DB::transaction(function () use ($user, $upload, $request, $audit, $databaseTenantContext): void {
            $databaseTenantContext->establishFamilySpace($upload->family_space_id);
            $user->forceFill(['avatar_media_upload_id' => $upload->id])->save();
            $audit->record('account.avatar_changed', $user, $user, $request, [
                'media_upload_id' => $upload->id,
                'family_space_id' => $upload->family_space_id,
            ]);
        });

        return response()->json(['data' => ['media_upload_id' => $upload->id]]);
    }

    public function destroy(
        Request $request,
        AuditRecorder $audit,
        DatabaseTenantContext $databaseTenantContext,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $user->refresh();
        $previousUpload = $user->avatarMediaUpload()->first();
        DB::transaction(function () use ($user, $previousUpload, $request, $audit, $databaseTenantContext): void {
            if ($previousUpload !== null) {
                $databaseTenantContext->establishFamilySpace($previousUpload->family_space_id);
            }
            $user->forceFill(['avatar_media_upload_id' => null])->save();
            $audit->record('account.avatar_removed', $user, $user, $request, [
                'media_upload_id' => $previousUpload?->id,
                'family_space_id' => $previousUpload?->family_space_id,
            ]);
        });

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function uploadPayload(MediaUpload $upload, ?UploadAuthorization $authorization): array
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
}
