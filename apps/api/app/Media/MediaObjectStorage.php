<?php

namespace App\Media;

use DateTimeInterface;

interface MediaObjectStorage
{
    public function authorizeSingleWrite(
        string $key,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): UploadAuthorization;

    public function inspect(string $key): ?StoredObject;

    public function downloadTo(string $key, string $localPath): void;

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void;

    public function delete(string $key): void;
}
