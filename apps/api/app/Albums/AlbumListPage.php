<?php

namespace App\Albums;

use App\Models\Album;
use Illuminate\Database\Eloquent\Collection;

final readonly class AlbumListPage
{
    /** @param Collection<int, Album> $items */
    public function __construct(public Collection $items, public ?string $nextCursor) {}
}
