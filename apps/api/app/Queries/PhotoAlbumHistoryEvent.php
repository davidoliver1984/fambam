<?php

namespace App\Queries;

final readonly class PhotoAlbumHistoryEvent
{
    public function __construct(
        public ?int $actorUserId,
        public string $eventType,
        public string $albumId,
        public string $createdAt,
    ) {}
}
