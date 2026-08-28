<?php

namespace App\FaceRecognition;

final readonly class FaceCandidate
{
    public function __construct(
        public string $personId,
        public float $bestCosineDistance,
        public int $referenceCount,
    ) {}
}
