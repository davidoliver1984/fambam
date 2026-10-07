<?php

namespace App\Media;

use DateTimeInterface;

interface AttachmentMediaDeliveryUrlSigner
{
    public function authorizeAttachmentRead(
        string $key,
        string $responseContentType,
        string $filename,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization;
}
