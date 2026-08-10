<?php

namespace App\Media;

interface FamilyMediaStorageCleaner
{
    public function deleteFamilyMedia(string $familySpaceId): void;
}
