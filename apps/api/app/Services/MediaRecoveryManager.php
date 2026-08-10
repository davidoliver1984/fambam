<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Jobs\GenerateCanonicalMediaUpload;
use App\Jobs\GeneratePresentationMediaVariants;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaRecoveryManager
{
    public function __construct(private readonly DatabaseTenantContext $databaseTenantContext) {}

    public function markCanonicalDegraded(
        TenantOperationContext $context,
        string $mediaUploadId,
        string $sourceSha256,
    ): void {
        DB::transaction(function () use ($context, $mediaUploadId, $sourceSha256): void {
            $this->establishContext($context);
            MediaUpload::query()
                ->whereKey($mediaUploadId)
                ->where('state', MediaUploadState::Preserved->value)
                ->where('original_sha256', $sourceSha256)
                ->update(['state' => MediaUploadState::Degraded->value]);
        });
    }

    public function retry(
        FamilySpace $familySpace,
        MediaUpload $upload,
        User $actor,
        Request $request,
    ): MediaUpload {
        $context = TenantOperationContext::fromRequest($familySpace, $actor, $request);
        $dispatch = DB::transaction(function () use ($context, $upload): ?array {
            $this->establishContext($context);
            $locked = MediaUpload::query()->lockForUpdate()->find($upload->id);
            if ($locked === null
                || $locked->state !== MediaUploadState::Degraded
                || $locked->original_object_key === null
                || $locked->original_sha256 === null) {
                return null;
            }

            if ($locked->canonical_object_key !== null && $locked->canonical_sha256 !== null) {
                $locked->update(['state' => MediaUploadState::Processing]);

                return ['variants', $locked->canonical_sha256];
            }

            $locked->update(['state' => MediaUploadState::Preserved]);

            return ['canonical', $locked->original_sha256];
        });

        if ($dispatch === null) {
            return $upload->refresh();
        }

        if ($dispatch[0] === 'variants') {
            GeneratePresentationMediaVariants::dispatch(
                $context->toArray(),
                $upload->id,
                $dispatch[1],
                (int) config('media.processing.variant_processing_version'),
            );
        } else {
            GenerateCanonicalMediaUpload::dispatch(
                $context->toArray(),
                $upload->id,
                $dispatch[1],
            );
        }

        return $upload->refresh();
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
