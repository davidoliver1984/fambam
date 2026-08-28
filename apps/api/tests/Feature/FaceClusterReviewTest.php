<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceClusterGenerationStatus;
use App\Enums\FaceClusterStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\FaceRecognition\FaceClusterReviewManager;
use App\Models\FaceAnalysisRun;
use App\Models\FaceCluster;
use App\Models\FaceClusterGeneration;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FaceClusterReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_merge_and_split_active_clusters_without_rewriting_history(): void
    {
        [$family, $owner, $membership, $generation, $observations] = $this->fixture(3);
        app(TenantContext::class)->establish($family, $membership, $owner);
        $first = $this->cluster($family, $generation, [$observations[0]->id]);
        $second = $this->cluster($family, $generation, [$observations[1]->id, $observations[2]->id]);
        $manager = app(FaceClusterReviewManager::class);
        $request = Request::create('/face-clusters', 'POST');

        $merged = $manager->merge([$first, $second], $owner, $request);

        $this->assertSame(FaceClusterStatus::Retired, $first->refresh()->status);
        $this->assertSame(FaceClusterStatus::Retired, $second->refresh()->status);
        $this->assertDatabaseCount('face_cluster_members', 6);
        $this->assertSame(3, DB::table('face_cluster_members')->where('face_cluster_id', $merged->id)
            ->where('is_active', true)->count());

        $replacements = $manager->split($merged, [
            [$observations[0]->id, $observations[2]->id],
            [$observations[1]->id],
        ], $owner, $request);

        $this->assertSame(FaceClusterStatus::Retired, $merged->refresh()->status);
        $this->assertCount(2, $replacements);
        $this->assertDatabaseCount('face_cluster_members', 9);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_cluster.merged']);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_cluster.split']);
    }

    public function test_member_may_propose_cluster_name_and_owner_confirmation_uses_assignments(): void
    {
        [$family, $owner, $ownerMembership, $generation, $observations] = $this->fixture(2);
        $member = User::factory()->create();
        $memberMembership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $cluster = $this->cluster($family, $generation, array_map(
            static fn (FaceObservation $observation): string => $observation->id,
            $observations,
        ));
        $tenant = app(TenantContext::class);
        $manager = app(FaceClusterReviewManager::class);
        $request = Request::create('/face-clusters/name', 'POST');

        $tenant->establish($family, $memberMembership, $member);
        $this->assertSame(2, $manager->proposeName($cluster, $person, $member, $request));
        $this->assertDatabaseCount('face_identity_assignments', 2);

        $tenant->establish($family, $ownerMembership, $owner);
        $this->assertSame(2, $manager->confirmName($cluster, $person, $owner, $request));
        $this->assertSame(FaceClusterStatus::Retired, $cluster->refresh()->status);
        $this->assertDatabaseCount('photo_people', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_cluster.named_and_retired']);
    }

    /** @return array{FamilySpace, User, FamilySpaceMembership, FaceClusterGeneration, list<FaceObservation>} */
    private function fixture(int $observationCount): array
    {
        $family = FamilySpace::factory()->create();
        $owner = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
            'state' => MembershipState::Active,
        ]);
        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id]);
        Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
        ]);
        $identity = config('image-analysis.identity');
        $run = FaceAnalysisRun::query()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'canonical_sha256' => str_repeat('b', 64),
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
        for ($index = 0; $index < $observationCount; $index++) {
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
        $generation = FaceClusterGeneration::query()->create([
            'family_space_id' => $family->id,
            'status' => FaceClusterGenerationStatus::Active,
            'activated_at' => now(),
        ]);

        return [$family, $owner, $membership, $generation, $observations];
    }

    /** @param list<string> $observationIds */
    private function cluster(FamilySpace $family, FaceClusterGeneration $generation, array $observationIds): FaceCluster
    {
        $cluster = FaceCluster::query()->create([
            'family_space_id' => $family->id,
            'clustering_generation_id' => $generation->id,
            'status' => FaceClusterStatus::Active,
        ]);
        DB::table('face_cluster_members')->insert(array_map(static fn (string $observationId): array => [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'face_cluster_id' => $cluster->id,
            'face_observation_id' => $observationId,
            'is_active' => true,
            'created_at' => now(),
        ], $observationIds));

        return $cluster;
    }
}
