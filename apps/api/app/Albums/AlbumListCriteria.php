<?php

namespace App\Albums;

final readonly class AlbumListCriteria
{
    /** @param list<string> $personIds */
    public function __construct(
        public string $sort,
        public int $limit,
        public ?string $cursor,
        public ?string $term = null,
        public ?string $location = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $tagId = null,
        public array $personIds = [],
        public ?string $eventId = null,
        public string $scope = '',
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'sort' => $this->sort,
            'q' => $this->term,
            'location' => $this->location,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'tag_id' => $this->tagId,
            'person_ids' => $this->personIds,
            'event_id' => $this->eventId,
            'scope' => $this->scope,
        ], JSON_THROW_ON_ERROR));
    }
}
