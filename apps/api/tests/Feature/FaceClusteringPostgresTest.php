<?php

namespace Tests\Feature;

use App\Enums\FaceClusterGenerationStatus;
use App\Enums\FaceClusterStatus;
use App\Enums\FamilySpaceStatus;
use App\FaceRecognition\FaceClusterGenerationManager;
use App\FaceRecognition\FaceEmbeddingProjectionManager;
use App\Media\FamilyMediaStorageCleaner;
use App\Services\FamilySpaceDeletionManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FaceClusteringPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Face clustering tests require runtime and administrative PostgreSQL connections.');
        }
        $this->admin = DB::connection('pgsql_admin');
        $this->admin->unprepared('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
        config()->set('face_recognition.clustering_max_cosine_distance', 0.2);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::purge('pgsql_admin');
        }
        parent::tearDown();
    }

    public function test_rebuild_atomically_supersedes_machine_clusters_and_preserves_human_retirement(): void
    {
        [$ownerId, $familySpaceId] = $this->createOwnedFamily('clustering-family');
        $runId = $this->createRun($familySpaceId, $ownerId);
        $first = $this->createObservation($familySpaceId, $runId, [1.0, 0.0, 0.0]);
        $second = $this->createObservation($familySpaceId, $runId, [0.99, 0.01, 0.0]);
        $third = $this->createObservation($familySpaceId, $runId, [0.0, 1.0, 0.0]);
        $fourth = $this->createObservation($familySpaceId, $runId, [0.0, 0.99, 0.01]);
        app(FaceEmbeddingProjectionManager::class)->rebuild(
            TenantOperationContext::forBackground($familySpaceId, $ownerId),
        );

        $manager = app(FaceClusterGenerationManager::class);
        $firstGeneration = $manager->rebuild(TenantOperationContext::forBackground($familySpaceId, $ownerId));
        $this->assertSame(FaceClusterGenerationStatus::Active, $firstGeneration->status);
        $this->assertSame(2, $this->admin->table('face_clusters')->where('clustering_generation_id', $firstGeneration->id)->count());
        $this->assertSame(4, $this->admin->table('face_cluster_members')->where('is_active', true)->count());

        $retiredClusterId = $this->admin->table('face_cluster_members')
            ->where('face_observation_id', $first)->value('face_cluster_id');
        $this->admin->table('face_cluster_members')->where('face_cluster_id', $retiredClusterId)
            ->update(['is_active' => false]);
        $this->admin->table('face_clusters')->where('id', $retiredClusterId)
            ->update(['status' => FaceClusterStatus::Retired->value, 'updated_at' => now()]);

        $secondGeneration = $manager->rebuild(TenantOperationContext::forBackground($familySpaceId, $ownerId));

        $this->assertSame(FaceClusterGenerationStatus::Superseded->value, $this->admin->table('face_cluster_generations')
            ->where('id', $firstGeneration->id)->value('status'));
        $this->assertSame(FaceClusterGenerationStatus::Active->value, $this->admin->table('face_cluster_generations')
            ->where('id', $secondGeneration->id)->value('status'));
        $this->assertSame(1, $this->admin->table('face_cluster_generations')->where('status', 'active')->count());
        $this->assertSame(FaceClusterStatus::Retired->value, $this->admin->table('face_clusters')
            ->where('id', $retiredClusterId)->value('status'));
        $this->assertSame(0, $this->admin->table('face_cluster_members')
            ->whereIn('face_observation_id', [$first, $second])
            ->where('face_cluster_id', '!=', $retiredClusterId)->count());
        $this->assertSame(2, $this->admin->table('face_cluster_members')
            ->whereIn('face_observation_id', [$third, $fourth])->where('is_active', true)->count());
        $this->assertSame(1, $this->admin->table('face_clusters')
            ->where('clustering_generation_id', $secondGeneration->id)->count());

        [$otherOwnerId, $otherFamilySpaceId] = $this->createOwnedFamily('other-clustering-family');
        try {
            $this->admin->table('face_cluster_members')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $otherFamilySpaceId,
                'face_cluster_id' => $this->admin->table('face_clusters')
                    ->where('clustering_generation_id', $secondGeneration->id)->value('id'),
                'face_observation_id' => $third,
                'is_active' => false,
                'created_at' => now(),
            ]);
            $this->fail('A cross-tenant cluster membership unexpectedly satisfied its composite foreign keys.');
        } catch (QueryException) {
        }

        $this->admin->table('family_spaces')->where('id', $familySpaceId)->update([
            'status' => FamilySpaceStatus::DeletionRequested->value,
            'deletion_requested_at' => now()->subDays(15),
            'deletion_requested_by' => $ownerId,
            'scheduled_deletion_at' => now()->subDay(),
            'updated_at' => now(),
        ]);
        $this->app->instance(FamilyMediaStorageCleaner::class, new FaceClusteringNoopStorageCleaner);
        app(FamilySpaceDeletionManager::class)->teardown(
            TenantOperationContext::forBackground($familySpaceId, $ownerId),
        );
        $this->assertSame(0, $this->admin->table('face_cluster_generations')->where('family_space_id', $familySpaceId)->count());
        $this->assertSame(0, $this->admin->table('face_clusters')->where('family_space_id', $familySpaceId)->count());
        $this->assertSame(0, $this->admin->table('face_cluster_members')->where('family_space_id', $familySpaceId)->count());
        $this->assertNotSame($ownerId, $otherOwnerId);
    }

    /** @return array{int, string} */
    private function createOwnedFamily(string $slug): array
    {
        $userId = (int) $this->admin->table('users')->insertGetId([
            'name' => Str::headline($slug),
            'email' => "{$slug}@example.test",
            'password' => 'not-used-in-this-test',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $familySpaceId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familySpaceId, $slug, $userId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familySpaceId,
                'slug' => $slug,
                'name' => Str::headline($slug),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $familySpaceId,
                'user_id' => $userId,
                'role' => 'owner',
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return [$userId, $familySpaceId];
    }

    private function createRun(string $familySpaceId, int $ownerId): string
    {
        $uploadId = (string) Str::ulid();
        $this->admin->table('media_uploads')->insert([
            'id' => $uploadId,
            'family_space_id' => $familySpaceId,
            'user_id' => $ownerId,
            'state' => 'ready',
            'staging_object_key' => "families/{$familySpaceId}/media-staging/{$uploadId}/original",
            'canonical_sha256' => hash('sha256', $uploadId),
            'client_filename' => 'synthetic.jpg',
            'idempotency_key' => "clustering-{$uploadId}",
            'request_fingerprint' => hash('sha256', "request-{$uploadId}"),
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => TenantOperationContext::newTraceparent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $identity = config('image-analysis.identity');
        $runId = (string) Str::ulid();
        $this->admin->table('face_analysis_runs')->insert([
            'id' => $runId,
            'family_space_id' => $familySpaceId,
            'media_upload_id' => $uploadId,
            'canonical_sha256' => hash('sha256', $uploadId),
            'contract_version' => '1',
            'provider' => $identity['provider'],
            'model_identifier' => $identity['model_identifier'],
            'model_weight_checksum' => $identity['model_weight_checksum'],
            'config_hash' => $identity['config_hash'],
            'status' => 'succeeded',
            'attempt_count' => 1,
            'succeeded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $runId;
    }

    /** @param list<float> $embedding */
    private function createObservation(string $familySpaceId, string $runId, array $embedding): string
    {
        $id = (string) Str::ulid();
        $this->admin->table('face_observations')->insert([
            'id' => $id,
            'family_space_id' => $familySpaceId,
            'face_analysis_run_id' => $runId,
            'face_index' => $this->admin->table('face_observations')->where('face_analysis_run_id', $runId)->count(),
            'bounds_x' => 0,
            'bounds_y' => 0,
            'bounds_width' => 1,
            'bounds_height' => 1,
            'landmarks' => '[]',
            'landmark_scheme' => '5-point',
            'detection_confidence' => 1,
            'embedding' => DB::raw("decode('".bin2hex(pack('g*', ...$embedding))."', 'hex')"),
            'embedding_dimension' => count($embedding),
            'embedding_dtype' => 'float32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

class FaceClusteringNoopStorageCleaner implements FamilyMediaStorageCleaner
{
    public function deleteFamilyMedia(string $familySpaceId): void {}
}
