<?php

namespace App\Media;

interface PerceptualHasher
{
    public function hash(string $canonicalPath): string;
}
