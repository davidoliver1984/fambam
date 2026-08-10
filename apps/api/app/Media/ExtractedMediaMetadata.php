<?php

namespace App\Media;

final readonly class ExtractedMediaMetadata
{
    public function __construct(
        public int $width,
        public int $height,
        public ?int $orientation,
        public ?string $cameraMake,
        public ?string $cameraModel,
        public ?string $captureTimestamp,
        public ?string $gpsLatitude,
        public ?string $gpsLongitude,
        public ?string $rawExif,
        public ?string $rawIccProfile,
    ) {}
}
