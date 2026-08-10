<?php

namespace App\Media;

use App\Enums\MediaVariantTransform;

interface PresentationVariantGenerator
{
    public function generate(string $canonicalPath, MediaVariantTransform $transform): GeneratedMediaVariant;
}
