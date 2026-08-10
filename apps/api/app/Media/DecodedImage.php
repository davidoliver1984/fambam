<?php

namespace App\Media;

final readonly class DecodedImage
{
    public function __construct(public int $width, public int $height) {}
}
