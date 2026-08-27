<?php

namespace App\FaceAnalysis;

use App\Enums\FaceAnalysisFailureCategory;
use JsonException;

class FaceAnalysisResultValidator
{
    public function validate(
        string $payload,
        int $canonicalWidth,
        int $canonicalHeight,
        int $declaredFaceCount,
    ): ValidatedFaceAnalysisResult {
        if (strlen($payload) > (int) config('image-analysis.result.max_bytes')) {
            $this->fail(FaceAnalysisFailureCategory::ResultArtifactOversized, 'Result artifact exceeds its byte limit.');
        }

        try {
            $decoded = json_decode(
                $payload,
                true,
                (int) config('image-analysis.result.max_json_depth'),
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $this->fail(FaceAnalysisFailureCategory::ResultArtifactInvalid, 'Result artifact is not valid bounded JSON.');
        }

        if (! is_array($decoded)
            || array_diff(array_keys($decoded), ['contract_version', 'faces']) !== []
            || ($decoded['contract_version'] ?? null) !== config('image-analysis.contract_version')
            || ! is_array($decoded['faces'] ?? null)) {
            $this->fail(FaceAnalysisFailureCategory::ResultArtifactInvalid, 'Result artifact envelope is invalid.');
        }

        $faces = $decoded['faces'];
        if (! array_is_list($faces)) {
            $this->fail(FaceAnalysisFailureCategory::ResultArtifactInvalid, 'Result faces must be a list.');
        }
        if (count($faces) > (int) config('image-analysis.result.max_faces')) {
            $this->fail(FaceAnalysisFailureCategory::ResultArtifactOversized, 'Result artifact exceeds its face-count limit.');
        }
        if (count($faces) !== $declaredFaceCount) {
            $this->fail(FaceAnalysisFailureCategory::ResultArtifactInvalid, 'Declared face count does not match the artifact.');
        }

        foreach ($faces as $face) {
            $this->validateFace($face, $canonicalWidth, $canonicalHeight);
        }

        /** @var list<array<string, mixed>> $faces */
        return new ValidatedFaceAnalysisResult((string) $decoded['contract_version'], $faces);
    }

    private function validateFace(mixed $face, int $canonicalWidth, int $canonicalHeight): void
    {
        $required = [
            'bounds', 'landmarks', 'landmark_scheme', 'detection_confidence',
            'embedding', 'embedding_dimension', 'embedding_dtype',
        ];
        $allowed = [...$required, 'quality_signals', 'provider_diagnostics'];
        if (! is_array($face)
            || array_is_list($face)
            || array_diff($required, array_keys($face)) !== []
            || array_diff(array_keys($face), $allowed) !== []) {
            $this->invalid('Detected face shape is invalid.');
        }

        $this->validateBounds($face['bounds'], $canonicalWidth, $canonicalHeight);
        $this->validateLandmarks(
            $face['landmarks'],
            $face['landmark_scheme'],
            $canonicalWidth,
            $canonicalHeight,
        );
        $this->finiteWithin($face['detection_confidence'], 0, 1, 'Detection confidence');
        $this->validateEmbedding(
            $face['embedding'],
            $face['embedding_dimension'],
            $face['embedding_dtype'],
        );
        $this->validateQualitySignals($face['quality_signals'] ?? []);
        $this->validateDiagnostics($face['provider_diagnostics'] ?? null);
    }

    private function validateBounds(mixed $bounds, int $width, int $height): void
    {
        if (! is_array($bounds)
            || array_diff(['x', 'y', 'width', 'height'], array_keys($bounds)) !== []
            || array_diff(array_keys($bounds), ['x', 'y', 'width', 'height']) !== []) {
            $this->invalid('Face bounds are invalid.');
        }
        $x = $this->finiteWithin($bounds['x'], 0, $width, 'Face x');
        $y = $this->finiteWithin($bounds['y'], 0, $height, 'Face y');
        $boxWidth = $this->finiteWithin($bounds['width'], 0, $width, 'Face width', false);
        $boxHeight = $this->finiteWithin($bounds['height'], 0, $height, 'Face height', false);
        if ($x + $boxWidth > $width || $y + $boxHeight > $height) {
            $this->invalid('Face bounds exceed the canonical dimensions.');
        }
    }

    private function validateLandmarks(mixed $landmarks, mixed $scheme, int $width, int $height): void
    {
        $schemes = config('image-analysis.result.landmark_schemes');
        if (! is_string($scheme) || ! is_array($schemes) || ! isset($schemes[$scheme])) {
            $this->invalid('Landmark scheme is unsupported.');
        }
        if (! is_array($landmarks) || ! array_is_list($landmarks) || count($landmarks) !== $schemes[$scheme]) {
            $this->invalid('Landmark count does not match its scheme.');
        }
        foreach ($landmarks as $point) {
            if (! is_array($point)
                || array_diff(['x', 'y'], array_keys($point)) !== []
                || array_diff(array_keys($point), ['x', 'y']) !== []) {
                $this->invalid('Landmark point is invalid.');
            }
            $this->finiteWithin($point['x'], 0, $width, 'Landmark x');
            $this->finiteWithin($point['y'], 0, $height, 'Landmark y');
        }
    }

    private function validateEmbedding(mixed $embedding, mixed $dimension, mixed $dtype): void
    {
        if (! is_int($dimension) || ! is_string($dtype) || ! is_array($embedding) || ! array_is_list($embedding)) {
            $this->invalid('Embedding shape is invalid.');
        }
        if (count($embedding) !== $dimension) {
            $this->invalid('Embedding length does not match its declared dimension.');
        }
        $supported = false;
        $shapes = config('image-analysis.result.embedding_shapes');
        if (is_array($shapes)) {
            foreach ($shapes as $shape) {
                if (is_array($shape)
                    && ($shape['dimension'] ?? null) === $dimension
                    && ($shape['dtype'] ?? null) === $dtype) {
                    $supported = true;
                    break;
                }
            }
        }
        if (! $supported) {
            $this->invalid('Embedding dimension or dtype is unsupported.');
        }
        foreach ($embedding as $value) {
            $this->finite($value, 'Embedding value');
        }
    }

    private function validateQualitySignals(mixed $signals): void
    {
        if (! is_array($signals) || ($signals !== [] && array_is_list($signals)) || count($signals) > 32) {
            $this->invalid('Quality signals are invalid.');
        }
        foreach ($signals as $value) {
            if ($value !== null && ! is_bool($value)) {
                $this->finite($value, 'Quality signal');
            }
        }
    }

    private function validateDiagnostics(mixed $diagnostics): void
    {
        if ($diagnostics === null) {
            return;
        }
        if (! is_array($diagnostics) || ($diagnostics !== [] && array_is_list($diagnostics))) {
            $this->invalid('Provider diagnostics are invalid.');
        }
        try {
            $encoded = json_encode($diagnostics, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid('Provider diagnostics are not valid JSON.');
        }
        if (strlen($encoded) > (int) config('image-analysis.result.max_provider_diagnostics_bytes')) {
            $this->fail(FaceAnalysisFailureCategory::ResultArtifactOversized, 'Provider diagnostics exceed their byte limit.');
        }
    }

    private function finiteWithin(mixed $value, float $minimum, float $maximum, string $label, bool $inclusiveMinimum = true): float
    {
        $number = $this->finite($value, $label);
        if (($inclusiveMinimum ? $number < $minimum : $number <= $minimum) || $number > $maximum) {
            $this->invalid("{$label} is outside its permitted range.");
        }

        return $number;
    }

    private function finite(mixed $value, string $label): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            $this->invalid("{$label} must be finite.");
        }

        return (float) $value;
    }

    private function invalid(string $message): never
    {
        $this->fail(FaceAnalysisFailureCategory::ResultArtifactInvalid, $message);
    }

    private function fail(FaceAnalysisFailureCategory $category, string $message): never
    {
        throw new InvalidFaceAnalysisResult($category, $message);
    }
}
