<?php

namespace App\Collections;

use App\Enums\CollectionPurpose;

final readonly class CollectionListCriteria
{
    /** @param list<CollectionPurpose> $purposes */
    public function __construct(
        public string $sort = 'updated',
        public ?string $term = null,
        public ?string $collectionId = null,
        public array $purposes = [],
    ) {}
}
