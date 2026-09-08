<?php

namespace App\Search;

final readonly class SearchCursor
{
    public function __construct(
        public string $group,
        public int $matchClass,
        public float $score,
        public ?string $tieBreaker,
        public string $id,
    ) {}
}
