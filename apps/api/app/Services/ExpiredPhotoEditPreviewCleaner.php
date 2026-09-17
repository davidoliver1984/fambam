<?php

namespace App\Services;

use App\Media\MediaObjectStorage;
use App\Models\PhotoEditPreview;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;

final class ExpiredPhotoEditPreviewCleaner
{
    public function __construct(
        private readonly DatabaseTenantContext $tenancy,
        private readonly MediaObjectStorage $storage,
    ) {}

    public function purge(TenantOperationContext $context, string $previewId): void
    {
        $key = DB::transaction(function () use ($context, $previewId): ?string {
            $this->tenancy->establishUser($context->actorUserId);
            $this->tenancy->establishFamilySpace($context->familySpaceId);
            $preview = PhotoEditPreview::query()->where('family_space_id', $context->familySpaceId)
                ->whereKey($previewId)->lockForUpdate()->first();

            return $preview !== null && $preview->expires_at->isPast() ? $preview->object_key : null;
        });
        if ($key === null) {
            return;
        }
        $this->storage->delete($key);
        DB::transaction(function () use ($context, $previewId, $key): void {
            $this->tenancy->establishUser($context->actorUserId);
            $this->tenancy->establishFamilySpace($context->familySpaceId);
            PhotoEditPreview::query()->where('family_space_id', $context->familySpaceId)
                ->whereKey($previewId)->where('object_key', $key)
                ->where('expires_at', '<=', now())->delete();
        });
    }
}
