<?php

namespace App\Enums;

enum MediaVariantTransform: string
{
    case Thumbnail = 'thumbnail';
    case Card = 'card';
    case Display = 'display';

    /** @return array{width: int, height: int, crop: bool} */
    public function dimensions(): array
    {
        return match ($this) {
            self::Thumbnail => ['width' => 320, 'height' => 320, 'crop' => true],
            self::Card => ['width' => 768, 'height' => 512, 'crop' => true],
            self::Display => ['width' => 2048, 'height' => 2048, 'crop' => false],
        };
    }
}
