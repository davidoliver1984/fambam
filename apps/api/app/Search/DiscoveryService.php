<?php

namespace App\Search;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;

interface DiscoveryService
{
    /** @return array<string, list<array<string, mixed>>> */
    public function fromPerson(Person $person, User $actor): array;

    /** @return array<string, list<array<string, mixed>>> */
    public function fromPhoto(Photo $photo, User $actor): array;

    /** @return array<string, list<array<string, mixed>>> */
    public function fromAlbum(Album $album, User $actor): array;

    /** @return array<string, list<array<string, mixed>>> */
    public function fromEvent(FamilyEvent $event, User $actor): array;
}
