<?php

namespace App\Media;

interface ImageDecoderValidator
{
    public function validate(string $path): DecodedImage;
}
