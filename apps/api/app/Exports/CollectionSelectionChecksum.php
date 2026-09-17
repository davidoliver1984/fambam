<?php

namespace App\Exports;

final class CollectionSelectionChecksum
{
    /** @param list<string> $photoIds */
    public function forPhotoIds(array $photoIds): string
    {
        $ids = array_values(array_unique($photoIds));
        sort($ids, SORT_STRING);

        return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    }
}
