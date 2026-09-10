<?php

namespace App\Exports;

final readonly class BuiltFamilyArchive
{
    public function __construct(
        public string $sha256,
        public int $byteSize,
        public int $photoCount,
    ) {}
}
