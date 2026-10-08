<?php

namespace App\People;

final readonly class PersonListCriteria
{
    public function __construct(
        public string $sort,
        public int $limit,
        public ?string $cursor,
        public ?string $term,
        public string $status,
        public string $scope,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'sort' => $this->sort,
            'q' => $this->term,
            'status' => $this->status,
            'scope' => $this->scope,
        ], JSON_THROW_ON_ERROR));
    }
}
