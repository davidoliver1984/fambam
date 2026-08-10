<?php

namespace App\Media;

use DateTimeInterface;

interface MediaObjectStorage
{
    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization;

    public function inspect(string $key): ?StoredObject;
}
