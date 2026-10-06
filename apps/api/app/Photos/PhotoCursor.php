<?php

namespace App\Photos;

final readonly class PhotoCursor
{
    public function __construct(
        public string $sort,
        public string $filters,
        public int $nullRank,
        public ?string $value,
        public string $id,
    ) {}
}
