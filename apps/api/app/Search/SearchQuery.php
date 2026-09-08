<?php

namespace App\Search;

final readonly class SearchQuery
{
    public function __construct(
        public ?string $term,
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $tagId,
        public int $limit,
        public ?string $cursor,
    ) {}
}
