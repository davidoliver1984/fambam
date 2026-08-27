<?php

namespace App\FaceAnalysis;

final readonly class ReceivedFaceAnalysisMessage
{
    public function __construct(
        public string $body,
        public string $receiptHandle,
    ) {}
}
