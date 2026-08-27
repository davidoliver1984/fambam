<?php

namespace App\FaceAnalysis;

final readonly class ValidatedFaceAnalysisResult
{
    /**
     * @param  list<array<string, mixed>>  $faces
     */
    public function __construct(
        public string $contractVersion,
        public array $faces,
    ) {}
}
