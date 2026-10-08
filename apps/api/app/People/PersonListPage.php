<?php

namespace App\People;

use App\Models\Person;
use Illuminate\Database\Eloquent\Collection;

final readonly class PersonListPage
{
    /** @param Collection<int, Person> $items */
    public function __construct(public Collection $items, public ?string $nextCursor) {}
}
