<?php

namespace App\Photos;

use App\Models\Photo;
use Illuminate\Database\Eloquent\Collection;

final readonly class PhotoListPage
{
    /** @param Collection<int, Photo> $items */
    public function __construct(public Collection $items, public ?string $nextCursor) {}
}
