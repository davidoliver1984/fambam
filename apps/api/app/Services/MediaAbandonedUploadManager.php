<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Media\MediaObjectStorage;
use App\Models\MediaUpload;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MediaAbandonedUploadManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function abandon(TenantOperationContext $context, string $mediaUploadId): void
    {
        $key = DB::transaction(function () use ($context, $mediaUploadId): ?string {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()->find($mediaUploadId);
            if ($upload === null || $upload->staging_deleted_at !== null) {
                return null;
            }

            if ($upload->state === MediaUploadState::Initiated) {
                if ($upload->created_at->isAfter($this->cutoff())) {
                    return null;
                }
                $upload->update(['state' => MediaUploadState::Abandoned]);
            } elseif ($upload->state !== MediaUploadState::Abandoned) {
                return null;
            }

            return $upload->staging_object_key;
        });

        if ($key === null) {
            return;
        }

        $this->storage->delete($key);

        DB::transaction(function () use ($context, $mediaUploadId, $key): void {
            $this->establishContext($context);
            MediaUpload::query()
                ->whereKey($mediaUploadId)
                ->where('state', MediaUploadState::Abandoned->value)
                ->where('staging_object_key', $key)
                ->whereNull('staging_deleted_at')
                ->update([
                    'staging_deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    private function cutoff(): Carbon
    {
        return now()->subHours((int) config('media.cleanup.abandoned_after_hours'));
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
