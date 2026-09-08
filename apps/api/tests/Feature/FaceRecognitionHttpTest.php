<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceClusterGenerationStatus;
use App\Enums\FaceClusterStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\FaceRecognition\EmbeddingSpaceIdentity;
use App\FaceRecognition\SimilarityMatch;
use App\FaceRecognition\SimilaritySearch;
use App\FaceRecognition\TrustedReferenceMatch;
use App\Models\FaceAnalysisRun;
use App\Models\FaceCluster;
use App\Models\FaceClusterGeneration;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceIdentitySuppression;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FaceRecognitionHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_may_browse_and_propose_but_cannot_call_authoritative_review_routes(): void
    {
        [$family, $owner, $member, $observations] = $this->fixture();
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'recognition_allowed' => true,
        ]);
        $assignment = FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observations[0]->id,
            'person_id' => $person->id,
            'proposal_source' => 'automatic_suggestion',
            'status' => 'pending',
        ]);
        $suppression = FaceIdentitySuppression::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observations[1]->id,
            'person_id' => $person->id,
            'decided_by' => $owner->id,
            'decided_at' => now(),
        ]);
        [$firstCluster, $secondCluster] = $this->clusters($family, $observations);
        $base = "/api/families/{$family->slug}";

        $this->actingAs($member)->getJson("{$base}/face-identity-assignments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.observation.image_width', 100)
            ->assertJsonPath('data.0.observation.image_height', 80);
        config()->set('face_recognition.processing_enabled', false);
        $this->actingAs($member)->getJson("{$base}/face-clusters")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('recognition_processing_enabled', false);
        config()->set('face_recognition.processing_enabled', true);
        $this->app->instance(SimilaritySearch::class, new FaceRecognitionHttpSimilaritySearch(
            $observations[0]->id,
            $person->id,
        ));
        $this->actingAs($member)->postJson(
            "{$base}/face-observations/{$observations[3]->id}/identity-suggestions",
        )->assertOk()
            ->assertJsonPath('data.band', 'shortlist')
            ->assertJsonPath('data.candidates.0.id', $person->id);
        $this->actingAs($member)->postJson(
            "{$base}/face-observations/{$observations[3]->id}/identity-assignments",
            ['person_id' => $person->id],
        )->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->actingAs($member)->postJson("{$base}/face-clusters/{$secondCluster->id}/name", [
            'person_id' => $person->id,
            'confirm' => false,
        ])->assertOk()->assertJsonPath('data.status', 'proposed');

        foreach ([
            ["{$base}/face-identity-assignments/{$assignment->id}/approve", []],
            ["{$base}/face-identity-assignments/{$assignment->id}/reject", []],
            ["{$base}/face-identity-suppressions/{$suppression->id}/reopen", []],
            ["{$base}/face-clusters/merge", ['cluster_ids' => [$firstCluster->id, $secondCluster->id]]],
            ["{$base}/face-clusters/{$firstCluster->id}/split", [
                'groups' => [[$observations[0]->id], [$observations[1]->id]],
            ]],
            ["{$base}/face-clusters/{$firstCluster->id}/name", [
                'person_id' => $person->id,
                'confirm' => true,
            ]],
        ] as [$uri, $payload]) {
            $this->actingAs($member)->postJson($uri, $payload)->assertForbidden();
        }
    }

    public function test_disabled_recognition_suggestions_return_a_structured_validation_response(): void
    {
        [$family, , $member, $observations] = $this->fixture();
        config()->set('face_recognition.processing_enabled', false);

        $this->actingAs($member)->postJson(
            "/api/families/{$family->slug}/face-observations/{$observations[0]->id}/identity-suggestions",
        )->assertUnprocessable()
            ->assertJsonPath(
                'errors.recognition_processing_disabled.0',
                'Recognition suggestions are not enabled for this family yet.',
            );
    }

    public function test_owner_can_resolve_assignment_suppression_and_cluster_actions_over_http(): void
    {
        [$family, $owner, , $observations] = $this->fixture();
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $assignment = FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observations[0]->id,
            'person_id' => $person->id,
            'proposal_source' => 'automatic_suggestion',
            'status' => 'pending',
        ]);
        $base = "/api/families/{$family->slug}";

        $this->actingAs($owner)->postJson("{$base}/face-identity-assignments/{$assignment->id}/reject")
            ->assertOk()->assertJsonPath('data.status', 'suppressed');
        $suppression = FaceIdentitySuppression::query()->firstOrFail();
        $this->actingAs($owner)->postJson("{$base}/face-identity-suppressions/{$suppression->id}/reopen")
            ->assertOk()->assertJsonPath('data.status', 'reopened');

        [$firstCluster, $secondCluster] = $this->clusters($family, [$observations[1], $observations[2]]);
        $mergedId = $this->actingAs($owner)->postJson("{$base}/face-clusters/merge", [
            'cluster_ids' => [$firstCluster->id, $secondCluster->id],
        ])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("{$base}/face-clusters/{$mergedId}/name", [
            'person_id' => $person->id,
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    /** @return array{FamilySpace, User, User, list<FaceObservation>} */
    private function fixture(): array
    {
        $family = FamilySpace::factory()->create(['slug' => 'face-review-family']);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        foreach ([[$owner, FamilySpaceRole::Owner], [$member, FamilySpaceRole::Member]] as [$user, $role]) {
            FamilySpaceMembership::factory()->create([
                'family_space_id' => $family->id,
                'user_id' => $user->id,
                'role' => $role,
                'state' => MembershipState::Active,
            ]);
        }
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'pixel_width' => 100,
            'pixel_height' => 80,
        ]);
        Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
        ]);
        $identity = config('image-analysis.identity');
        $run = FaceAnalysisRun::query()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'canonical_sha256' => str_repeat('d', 64),
            'contract_version' => '1',
            'provider' => $identity['provider'],
            'model_identifier' => $identity['model_identifier'],
            'model_weight_checksum' => $identity['model_weight_checksum'],
            'config_hash' => $identity['config_hash'],
            'status' => FaceAnalysisRunStatus::Succeeded,
            'attempt_count' => 1,
            'succeeded_at' => now(),
        ]);
        $observations = [];
        foreach (range(0, 3) as $index) {
            $observations[] = FaceObservation::query()->create([
                'family_space_id' => $family->id,
                'face_analysis_run_id' => $run->id,
                'face_index' => $index,
                'bounds_x' => 0,
                'bounds_y' => 0,
                'bounds_width' => 1,
                'bounds_height' => 1,
                'landmarks' => [],
                'landmark_scheme' => '5-point',
                'detection_confidence' => 1,
                'embedding' => pack('g*', 1.0, 0.0, 0.0),
                'embedding_dimension' => 3,
                'embedding_dtype' => 'float32',
            ]);
        }

        return [$family, $owner, $member, $observations];
    }

    /** @param list<FaceObservation> $observations
     * @return array{FaceCluster, FaceCluster}
     */
    private function clusters(FamilySpace $family, array $observations): array
    {
        $generation = FaceClusterGeneration::query()->where('family_space_id', $family->id)
            ->where('status', FaceClusterGenerationStatus::Active)->first();
        if ($generation === null) {
            $generation = FaceClusterGeneration::query()->create([
                'family_space_id' => $family->id,
                'status' => FaceClusterGenerationStatus::Active,
                'activated_at' => now(),
            ]);
        }
        $groups = count($observations) === 2 ? [[$observations[0]], [$observations[1]]] : [
            [$observations[0], $observations[1]],
            [$observations[2]],
        ];
        $clusters = [];
        foreach ($groups as $group) {
            $cluster = FaceCluster::query()->create([
                'family_space_id' => $family->id,
                'clustering_generation_id' => $generation->id,
                'status' => FaceClusterStatus::Active,
            ]);
            foreach ($group as $observation) {
                DB::table('face_cluster_members')->insert([
                    'id' => (string) Str::ulid(),
                    'family_space_id' => $family->id,
                    'face_cluster_id' => $cluster->id,
                    'face_observation_id' => $observation->id,
                    'is_active' => true,
                    'created_at' => now(),
                ]);
            }
            $clusters[] = $cluster;
        }

        return $clusters;
    }
}

class FaceRecognitionHttpSimilaritySearch implements SimilaritySearch
{
    public function __construct(
        private readonly string $referenceObservationId,
        private readonly string $personId,
    ) {}

    public function nearest(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array {
        return [new SimilarityMatch($this->referenceObservationId, 0.5)];
    }

    public function nearestTrustedReferences(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array {
        return [new TrustedReferenceMatch($this->referenceObservationId, $this->personId, 0.5)];
    }
}
