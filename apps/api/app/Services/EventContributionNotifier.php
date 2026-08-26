<?php

namespace App\Services;

use App\Jobs\SendEventContributionNotifications;
use App\Models\Album;
use App\Models\Photo;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;

class EventContributionNotifier
{
    public function dispatch(Album $album, Photo $photo, TenantOperationContext $context): void
    {
        if ($album->event_id === null) {
            return;
        }

        DB::afterCommit(fn () => SendEventContributionNotifications::dispatch(
            $context->toArray(),
            $album->event_id,
            $photo->id,
        ));
    }
}
