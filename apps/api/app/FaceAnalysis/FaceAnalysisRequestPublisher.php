<?php

namespace App\FaceAnalysis;

interface FaceAnalysisRequestPublisher
{
    /** @param array<string, mixed> $message */
    public function publish(array $message): void;
}
