<?php

namespace App\Storage;

use App\Models\FamilySpace;
use InvalidArgumentException;

final class FamilyStorageKey
{
    public static function for(FamilySpace|string $familySpace, string $relativePath): string
    {
        $familySpaceId = $familySpace instanceof FamilySpace ? $familySpace->id : $familySpace;
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $segments = explode('/', $relativePath);

        if ($relativePath === ''
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || str_contains($relativePath, "\0")) {
            throw new InvalidArgumentException('A safe Family Space relative storage path is required.');
        }

        return "families/{$familySpaceId}/{$relativePath}";
    }
}
