<?php

namespace App\Photos;

use App\Models\Photo;
use Illuminate\Database\Eloquent\Collection;

final readonly class PhotoCreationResult
{
    /** @param Collection<int, Photo> $candidates */
    private function __construct(
        public string $outcome,
        public ?Photo $photo,
        public Collection $candidates,
    ) {}

    /** @param Collection<int, Photo> $candidates */
    public static function duplicateDetected(Collection $candidates): self
    {
        return new self('duplicate_detected', null, $candidates);
    }

    public static function created(Photo $photo): self
    {
        return new self('photo_created', $photo, new Collection);
    }

    public static function existing(Photo $photo): self
    {
        return new self('existing_photo', $photo, new Collection);
    }

    public static function cancelled(): self
    {
        return new self('cancelled', null, new Collection);
    }
}
