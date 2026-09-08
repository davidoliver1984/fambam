<?php

namespace App\Search;

use App\Search\Summaries\SearchSummary;

final readonly class SearchPage
{
    /** @param list<SearchSummary> $items */
    public function __construct(public array $items, public ?string $nextCursor) {}

    /** @return array{items: list<array<string, mixed>>, next_cursor: ?string} */
    public function toArray(): array
    {
        return [
            'items' => array_map(fn (SearchSummary $item): array => $item->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
        ];
    }
}
