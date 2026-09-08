<?php

namespace App\Search\Summaries;

final readonly class EventSearchSummary implements SearchSummary
{
    public function __construct(
        private string $id,
        private string $name,
        private ?string $description,
        private ?string $location,
        private ?string $startsOn,
        private ?string $endsOn,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'location' => $this->location,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
        ];
    }
}
