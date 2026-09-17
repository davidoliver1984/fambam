<?php

namespace App\Media;

final readonly class PhotoPresentationAsset
{
    public function __construct(
        public string $objectKey,
        public string $mimeType,
        public string $extension,
        public ?string $expectedSha256,
        public ?string $photoVersionId,
    ) {}
}
