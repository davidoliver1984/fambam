<?php

namespace App\FaceRecognition;

use App\Enums\FaceSuggestionBand;

final readonly class FaceSuggestionOutcome
{
    /** @param list<FaceCandidate> $candidates */
    public function __construct(
        public FaceSuggestionBand $band,
        public array $candidates,
        public ?string $assignmentId = null,
    ) {}
}
