<?php

namespace App\FaceRecognition;

use App\Models\FaceAnalysisRun;

final readonly class EmbeddingSpaceIdentity
{
    public function __construct(
        public string $provider,
        public string $modelIdentifier,
        public string $modelWeightChecksum,
        public string $configHash,
    ) {}

    public static function fromRun(FaceAnalysisRun $run): self
    {
        return new self(
            $run->provider,
            $run->model_identifier,
            $run->model_weight_checksum,
            $run->config_hash,
        );
    }
}
