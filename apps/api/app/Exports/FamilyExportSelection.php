<?php

namespace App\Exports;

final readonly class FamilyExportSelection
{
    /**
     * @param  list<string>  $photoIds
     * @param  list<string>  $contextPhotoIds
     * @param  list<string>  $albumIds
     * @param  list<string>  $eventIds
     * @param  list<string>  $storyIds
     * @param  list<string>  $commentIds
     * @param  list<string>  $reactionIds
     * @param  list<string>  $personIds
     * @param  list<string>  $savedSearchIds
     * @param  list<string>  $originalPhotoIds
     * @param  list<string>  $unattachedMediaUploadIds
     */
    public function __construct(
        public array $photoIds,
        public array $contextPhotoIds,
        public array $albumIds,
        public array $eventIds,
        public array $storyIds,
        public array $commentIds,
        public array $reactionIds,
        public array $personIds,
        public array $savedSearchIds,
        public array $originalPhotoIds,
        public array $unattachedMediaUploadIds,
    ) {}
}
