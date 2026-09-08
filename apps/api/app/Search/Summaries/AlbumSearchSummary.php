<?php

namespace App\Search\Summaries;

final readonly class AlbumSearchSummary implements SearchSummary
{
    public function __construct(
        private string $id,
        private string $name,
        private ?string $description,
        private string $visibility,
        private ?string $eventId,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'event_id' => $this->eventId,
        ];
    }
}
