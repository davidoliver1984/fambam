<?php

namespace App\Media;

use Carbon\CarbonImmutable;

final readonly class UploadAuthorization
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $url,
        public array $headers,
        public CarbonImmutable $expiresAt,
    ) {}
}
