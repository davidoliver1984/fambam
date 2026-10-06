<?php

namespace App\Photos;

final readonly class PhotoListCriteria
{
    public function __construct(
        public string $sort,
        public int $limit,
        public ?string $cursor,
        public ?string $term = null,
        public ?string $personId = null,
        public ?string $tag = null,
        public ?string $location = null,
        public ?int $historicalYear = null,
        public bool $withoutConfirmedDate = false,
        public bool $withoutAlbum = false,
        public string $scope = '',
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'sort' => $this->sort,
            'q' => $this->term,
            'person_id' => $this->personId,
            'tag' => $this->tag,
            'location' => $this->location,
            'historical_year' => $this->historicalYear,
            'without_confirmed_date' => $this->withoutConfirmedDate,
            'without_album' => $this->withoutAlbum,
            'scope' => $this->scope,
        ], JSON_THROW_ON_ERROR));
    }
}
