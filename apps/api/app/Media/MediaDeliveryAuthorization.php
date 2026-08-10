<?php

namespace App\Media;

use Carbon\CarbonImmutable;

final readonly class MediaDeliveryAuthorization
{
    public function __construct(
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
