<?php

namespace App\Search;

use App\Models\User;

interface SearchService
{
    public function people(SearchQuery $query, User $actor): SearchPage;

    public function photos(SearchQuery $query, User $actor): SearchPage;

    public function albums(SearchQuery $query, User $actor): SearchPage;

    public function stories(SearchQuery $query, User $actor): SearchPage;

    public function events(SearchQuery $query, User $actor): SearchPage;

    /** @return list<array{id: string, label: string}> */
    public function suggest(string $type, string $prefix, User $actor): array;
}
