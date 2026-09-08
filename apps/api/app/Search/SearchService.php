<?php

namespace App\Search;

use App\Models\User;

interface SearchService
{
    public function photos(SearchQuery $query, User $actor): SearchPage;

    public function albums(SearchQuery $query, User $actor): SearchPage;

    public function stories(SearchQuery $query, User $actor): SearchPage;
}
