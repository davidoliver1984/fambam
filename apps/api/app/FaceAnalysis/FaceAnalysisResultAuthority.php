<?php

namespace App\FaceAnalysis;

use App\Media\UploadAuthorization;
use DateTimeInterface;

interface FaceAnalysisResultAuthority
{
    public function authorizeWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization;
}
