<?php

namespace App\Search;

final readonly class SearchQuery
{
    /** @param list<string> $personIds */
    public function __construct(
        public ?string $term,
        public ?string $dateFrom,
        public ?string $dateTo,
        public ?string $tagId,
        public int $limit,
        public ?string $cursor,
        public array $personIds = [],
        public ?string $eventId = null,
    ) {}
}
