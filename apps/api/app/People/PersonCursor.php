<?php

namespace App\People;

final readonly class PersonCursor
{
    public function __construct(
        public string $sort,
        public string $filters,
        public string $name,
        public string $id,
    ) {}
}
