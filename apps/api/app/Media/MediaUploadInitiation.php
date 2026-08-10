<?php

namespace App\Media;

use App\Models\MediaUpload;

final readonly class MediaUploadInitiation
{
    public function __construct(
        public MediaUpload $upload,
        public ?UploadAuthorization $authorization,
        public bool $created,
    ) {}
}
