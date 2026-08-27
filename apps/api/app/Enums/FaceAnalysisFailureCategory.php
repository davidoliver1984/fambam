<?php

namespace App\Enums;

enum FaceAnalysisFailureCategory: string
{
    case ChecksumMismatch = 'checksum_mismatch';
    case CanonicalUnavailable = 'canonical_unavailable';
    case DecodeError = 'decode_error';
    case InferenceError = 'inference_error';
    case Timeout = 'timeout';
    case ResultChecksumMismatch = 'result_checksum_mismatch';
    case ResultArtifactInvalid = 'result_artifact_invalid';
    case ResultArtifactOversized = 'result_artifact_oversized';
    case AttemptTimedOut = 'attempt_timed_out';
}
