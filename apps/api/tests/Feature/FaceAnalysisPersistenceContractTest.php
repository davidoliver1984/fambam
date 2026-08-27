<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisAttemptStatus;
use App\Enums\FaceAnalysisRunStatus;
use App\Enums\MediaUploadState;
use App\Models\FaceAnalysisAttempt;
use App\Models\FaceAnalysisRun;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaceAnalysisPersistenceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_attempt_and_observation_have_distinct_stable_identity(): void
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Ready,
            'canonical_sha256' => str_repeat('a', 64),
        ]);
        $run = FaceAnalysisRun::query()->create($this->runAttributes($family, $upload));
        $attempt = FaceAnalysisAttempt::query()->create([
            'family_space_id' => $family->id,
            'face_analysis_run_id' => $run->id,
            'expected_result_object_key' => "families/{$family->id}/face-analysis/attempt/result.json",
            'status' => FaceAnalysisAttemptStatus::Dispatched,
            'dispatched_at' => now(),
        ]);
        $observation = FaceObservation::query()->create([
            'family_space_id' => $family->id,
            'face_analysis_run_id' => $run->id,
            'face_index' => 0,
            'bounds_x' => 1,
            'bounds_y' => 2,
            'bounds_width' => 3,
            'bounds_height' => 4,
            'landmarks' => [],
            'landmark_scheme' => '5-point',
            'detection_confidence' => 0.95,
            'embedding' => pack('g*', ...array_fill(0, 512, 0.01)),
            'embedding_dimension' => 512,
            'embedding_dtype' => 'float32',
            'quality_signals' => [],
        ]);

        $this->assertSame(FaceAnalysisRunStatus::Pending, $run->status);
        $this->assertSame(FaceAnalysisAttemptStatus::Dispatched, $attempt->status);
        $this->assertSame($run->id, $observation->run->id);
        $this->assertSame($upload->id, $run->mediaUpload->id);
    }

    public function test_logical_analysis_identity_is_unique(): void
    {
        $family = FamilySpace::factory()->create();
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'state' => MediaUploadState::Ready,
            'canonical_sha256' => str_repeat('a', 64),
        ]);
        FaceAnalysisRun::query()->create($this->runAttributes($family, $upload));

        $this->expectException(QueryException::class);
        FaceAnalysisRun::query()->create($this->runAttributes($family, $upload));
    }

    /** @return array<string, mixed> */
    private function runAttributes(FamilySpace $family, MediaUpload $upload): array
    {
        return [
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'canonical_sha256' => $upload->canonical_sha256,
            'contract_version' => '1',
            'provider' => 'synthetic',
            'model_identifier' => 'synthetic-model',
            'model_weight_checksum' => str_repeat('b', 64),
            'config_hash' => str_repeat('c', 64),
            'status' => FaceAnalysisRunStatus::Pending,
        ];
    }
}
