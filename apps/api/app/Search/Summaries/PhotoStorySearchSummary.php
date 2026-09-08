<?php

namespace App\Search\Summaries;

final readonly class PhotoStorySearchSummary implements SearchSummary
{
    public function __construct(
        private string $id,
        private string $photoId,
        private ?string $photoCaption,
        private string $mediaUploadId,
        private string $excerpt,
        private string $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'photo_id' => $this->photoId,
            'photo_caption' => $this->photoCaption,
            'media_upload_id' => $this->mediaUploadId,
            'excerpt' => $this->excerpt,
            'created_at' => $this->createdAt,
        ];
    }
}
