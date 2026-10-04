<?php

namespace App\Albums;

use App\Models\Album;
use Carbon\CarbonImmutable;

final class AlbumFreshness
{
    public function isNew(Album $album): bool
    {
        if ($album->created_at === null) {
            return false;
        }

        $boundary = CarbonImmutable::now()->subDays((int) config('albums.new_window_days'));

        // Inclusive boundary: an Album created exactly one configured window
        // ago is still new. A future timestamp caused by bounded clock skew is
        // also treated as new; updated_at and the historical Album date never
        // participate in this presentation flag.
        return $album->created_at->greaterThanOrEqualTo($boundary);
    }
}
