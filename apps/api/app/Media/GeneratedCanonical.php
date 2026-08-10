<?php

namespace App\Media;

final readonly class GeneratedCanonical
{
    public function __construct(
        public string $path,
        public string $extension,
        public string $mimeType,
        public string $sha256,
    ) {}
}
