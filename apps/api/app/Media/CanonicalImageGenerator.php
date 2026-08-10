<?php

namespace App\Media;

interface CanonicalImageGenerator
{
    public function generate(string $sourcePath): GeneratedCanonical;
}
