<?php

namespace App\Search\Summaries;

final readonly class PersonSearchSummary implements SearchSummary
{
    public function __construct(private string $id, private string $preferredName) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'preferred_name' => $this->preferredName];
    }
}
