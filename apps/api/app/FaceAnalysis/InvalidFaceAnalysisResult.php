<?php

namespace App\FaceAnalysis;

use App\Enums\FaceAnalysisFailureCategory;
use RuntimeException;

class InvalidFaceAnalysisResult extends RuntimeException
{
    public function __construct(
        public readonly FaceAnalysisFailureCategory $category,
        string $message,
    ) {
        parent::__construct($message);
    }
}
