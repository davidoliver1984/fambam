<?php

namespace App\Search\Summaries;

interface SearchSummary
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
