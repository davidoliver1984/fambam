<?php

namespace App\Media;

interface MediaMetadataExtractor
{
    public function extract(string $path): ExtractedMediaMetadata;
}
