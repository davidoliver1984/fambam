<?php

namespace App\Albums;

final readonly class AlbumCursor
{
    public function __construct(
        public string $sort,
        public string $filters,
        public int $nullRank,
        public ?string $value,
        public string $id,
    ) {}
}
