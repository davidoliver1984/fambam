<?php

namespace App\FaceRecognition;

final readonly class TrustedReferenceMatch
{
    public function __construct(
        public string $faceObservationId,
        public string $personId,
        public float $cosineDistance,
    ) {}
}
