<?php

namespace App\Services;

use App\Enums\MediaUploadState;
use App\Media\MediaObjectStorage;
use App\Models\MediaUpload;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MediaQuarantineRetentionManager
{
    public function __construct(
        private readonly MediaObjectStorage $storage,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function purge(TenantOperationContext $context, string $mediaUploadId): void
    {
        $key = DB::transaction(function () use ($context, $mediaUploadId): ?string {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()->find($mediaUploadId);
            if ($upload === null
                || $upload->state !== MediaUploadState::Quarantined
                || $upload->quarantine_object_key === null
                || $upload->updated_at->isAfter($this->cutoff())) {
                return null;
            }

            return $upload->quarantine_object_key;
        });

        if ($key === null) {
            return;
        }

        $this->storage->delete($key);

        DB::transaction(function () use ($context, $mediaUploadId, $key): void {
            $this->establishContext($context);
            MediaUpload::query()
                ->whereKey($mediaUploadId)
                ->where('state', MediaUploadState::Quarantined->value)
                ->where('quarantine_object_key', $key)
                ->update([
                    'quarantine_object_key' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    private function cutoff(): Carbon
    {
        return now()->subDays((int) config('media.cleanup.quarantine_retention_days'));
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
