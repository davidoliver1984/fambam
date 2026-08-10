<?php

namespace App\Media;

use RuntimeException;

class MediaValidationFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Media validation failed: {$reason}");
    }
}
