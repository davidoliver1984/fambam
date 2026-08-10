<?php

namespace App\Media;

enum DetectedMediaFormat: string
{
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Heic = 'heic';
    case Heif = 'heif';
    case Webp = 'webp';
    case Tiff = 'tiff';

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Heic => 'heic',
            self::Heif => 'heif',
            self::Webp => 'webp',
            self::Tiff => 'tif',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Heic => 'image/heic',
            self::Heif => 'image/heif',
            self::Webp => 'image/webp',
            self::Tiff => 'image/tiff',
        };
    }
}
