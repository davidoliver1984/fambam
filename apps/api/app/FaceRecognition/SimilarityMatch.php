<?php

namespace App\FaceRecognition;

final readonly class SimilarityMatch
{
    public function __construct(
        public string $faceObservationId,
        public float $cosineDistance,
    ) {}
}
