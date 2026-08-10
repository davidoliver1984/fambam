<?php

namespace App\Media;

final readonly class StoredObject
{
    public function __construct(public int $byteSize) {}
}
