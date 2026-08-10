<?php

namespace App\Media;

final readonly class GeneratedMediaVariant
{
    public function __construct(
        public string $path,
        public string $extension,
        public string $mimeType,
        public string $sha256,
        public int $width,
        public int $height,
        public int $byteSize,
    ) {}
}
