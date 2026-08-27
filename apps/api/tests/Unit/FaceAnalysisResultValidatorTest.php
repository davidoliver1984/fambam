<?php

namespace Tests\Unit;

use App\Enums\FaceAnalysisFailureCategory;
use App\FaceAnalysis\FaceAnalysisResultValidator;
use App\FaceAnalysis\InvalidFaceAnalysisResult;
use Closure;
use Tests\TestCase;

class FaceAnalysisResultValidatorTest extends TestCase
{
    public function test_valid_result_is_accepted_with_matching_declared_count(): void
    {
        $result = app(FaceAnalysisResultValidator::class)->validate(
            json_encode($this->artifact(), JSON_THROW_ON_ERROR),
            100,
            80,
            1,
        );

        $this->assertSame('1', $result->contractVersion);
        $this->assertCount(1, $result->faces);
    }

    public function test_oversized_artifact_is_rejected_before_parsing(): void
    {
        config(['image-analysis.result.max_bytes' => 10]);

        $this->assertInvalid(
            fn () => app(FaceAnalysisResultValidator::class)->validate('{"larger":true}', 100, 80, 0),
            FaceAnalysisFailureCategory::ResultArtifactOversized,
        );
    }

    public function test_declared_face_count_and_embedding_dimension_are_verified(): void
    {
        $artifact = $this->artifact();
        $artifact['faces'][0]['embedding_dimension'] = 511;

        $this->assertInvalid(
            fn () => app(FaceAnalysisResultValidator::class)->validate(
                json_encode($artifact, JSON_THROW_ON_ERROR),
                100,
                80,
                1,
            ),
            FaceAnalysisFailureCategory::ResultArtifactInvalid,
        );
    }

    public function test_out_of_frame_geometry_is_rejected(): void
    {
        $artifact = $this->artifact();
        $artifact['faces'][0]['bounds']['x'] = 90.0;

        $this->assertInvalid(
            fn () => app(FaceAnalysisResultValidator::class)->validate(
                json_encode($artifact, JSON_THROW_ON_ERROR),
                100,
                80,
                1,
            ),
            FaceAnalysisFailureCategory::ResultArtifactInvalid,
        );
    }

    public function test_unknown_contract_version_and_excessive_json_depth_fail_closed(): void
    {
        config(['image-analysis.result.max_json_depth' => 4]);

        $this->assertInvalid(
            fn () => app(FaceAnalysisResultValidator::class)->validate(
                '{"contract_version":"2","faces":[{"nested":{"too":{"deep":true}}}]}',
                100,
                80,
                1,
            ),
            FaceAnalysisFailureCategory::ResultArtifactInvalid,
        );
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        return [
            'contract_version' => '1',
            'faces' => [[
                'bounds' => ['x' => 10.0, 'y' => 10.0, 'width' => 20.0, 'height' => 20.0],
                'landmarks' => [
                    ['x' => 15.0, 'y' => 15.0],
                    ['x' => 25.0, 'y' => 15.0],
                    ['x' => 20.0, 'y' => 20.0],
                    ['x' => 16.0, 'y' => 25.0],
                    ['x' => 24.0, 'y' => 25.0],
                ],
                'landmark_scheme' => '5-point',
                'detection_confidence' => 0.98,
                'embedding' => array_fill(0, 512, 0.001),
                'embedding_dimension' => 512,
                'embedding_dtype' => 'float32',
                'quality_signals' => ['blur' => 0.1],
                'provider_diagnostics' => ['detector_stage' => 'synthetic'],
            ]],
        ];
    }

    private function assertInvalid(Closure $operation, FaceAnalysisFailureCategory $category): void
    {
        try {
            $operation();
            $this->fail('Expected an invalid face-analysis result.');
        } catch (InvalidFaceAnalysisResult $exception) {
            $this->assertSame($category, $exception->category);
        }
    }
}
