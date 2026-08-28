<?php

namespace Tests\Feature;

use App\FaceRecognition\EmbeddingSpaceIdentity;
use App\FaceRecognition\FaceEmbeddingProjectionManager;
use App\FaceRecognition\SimilaritySearch;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FaceEmbeddingProjectionPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Face embedding projection tests require runtime and administrative PostgreSQL connections.');
        }
        $this->admin = DB::connection('pgsql_admin');
        $this->admin->unprepared('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::purge('pgsql_admin');
        }
        parent::tearDown();
    }

    public function test_projection_rebuild_is_deterministic_and_similarity_is_tenant_and_identity_scoped(): void
    {
        [$ownerId, $familySpaceId] = $this->createOwnedFamily('projection-family');
        [$otherOwnerId, $otherFamilySpaceId] = $this->createOwnedFamily('other-projection-family');
        $run = $this->createRun($familySpaceId, $ownerId, 'model-a');
        $otherIdentityRun = $this->createRun($familySpaceId, $ownerId, 'model-b');
        $otherTenantRun = $this->createRun($otherFamilySpaceId, $otherOwnerId, 'model-a');
        $exact = $this->createObservation($familySpaceId, $run, [1.0, 0.0, 0.0]);
        $near = $this->createObservation($familySpaceId, $run, [0.9, 0.1, 0.0]);
        $incompatible = $this->createObservation($familySpaceId, $otherIdentityRun, [1.0, 0.0, 0.0]);
        $otherTenant = $this->createObservation($otherFamilySpaceId, $otherTenantRun, [1.0, 0.0, 0.0]);
        $originalBytes = $this->binary($this->admin->table('face_observations')->where('id', $exact)->value('embedding'));

        try {
            $this->admin->statement(<<<'SQL'
INSERT INTO face_embedding_projections (
    id, family_space_id, face_observation_id, projection_version,
    source_checksum, embedding_dimension, vector, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS vector), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
SQL, [
                (string) Str::ulid(),
                $familySpaceId,
                $otherTenant,
                'float32-le-v1',
                hash('sha256', pack('g*', 1.0, 0.0, 0.0)),
                3,
                '[1,0,0]',
            ]);
            $this->fail('A cross-tenant FaceObservation projection unexpectedly satisfied its composite foreign key.');
        } catch (QueryException) {
        }

        $manager = app(FaceEmbeddingProjectionManager::class);
        $this->assertSame(3, $manager->rebuild(TenantOperationContext::forBackground($familySpaceId, $ownerId)));
        $this->assertSame(1, $manager->rebuild(TenantOperationContext::forBackground($otherFamilySpaceId, $otherOwnerId)));

        $matches = DB::transaction(function () use ($ownerId, $familySpaceId): array {
            $tenant = app(DatabaseTenantContext::class);
            $tenant->establishUser($ownerId);
            $tenant->establishFamilySpace($familySpaceId);

            return app(SimilaritySearch::class)->nearest(
                $familySpaceId,
                new EmbeddingSpaceIdentity('synthetic', 'model-a', str_repeat('c', 64), str_repeat('d', 64)),
                [1.0, 0.0, 0.0],
                10,
            );
        });

        $this->assertSame([$exact, $near], array_map(fn ($match): string => $match->faceObservationId, $matches));
        $this->assertSame(0.0, $matches[0]->cosineDistance);
        $this->assertNotContains($incompatible, array_map(fn ($match): string => $match->faceObservationId, $matches));
        $this->assertNotContains($otherTenant, array_map(fn ($match): string => $match->faceObservationId, $matches));

        $this->admin->table('face_embedding_projections')->where('face_observation_id', $exact)->update([
            'source_checksum' => str_repeat('0', 64),
        ]);
        $withoutDrifted = DB::transaction(function () use ($ownerId, $familySpaceId): array {
            $tenant = app(DatabaseTenantContext::class);
            $tenant->establishUser($ownerId);
            $tenant->establishFamilySpace($familySpaceId);

            return app(SimilaritySearch::class)->nearest(
                $familySpaceId,
                new EmbeddingSpaceIdentity('synthetic', 'model-a', str_repeat('c', 64), str_repeat('d', 64)),
                [1.0, 0.0, 0.0],
                10,
            );
        });
        $this->assertSame([$near], array_map(fn ($match): string => $match->faceObservationId, $withoutDrifted));

        $this->assertSame(1, $manager->rebuild(
            TenantOperationContext::forBackground($familySpaceId, $ownerId),
            [$exact],
        ));
        $this->assertSame(hash('sha256', $originalBytes), $this->admin->table('face_embedding_projections')
            ->where('face_observation_id', $exact)->value('source_checksum'));
        $this->assertSame($originalBytes, $this->binary(
            $this->admin->table('face_observations')->where('id', $exact)->value('embedding'),
        ));
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

    private function createRun(string $familySpaceId, int $ownerId, string $model): string
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
            'idempotency_key' => "projection-{$uploadId}",
            'request_fingerprint' => hash('sha256', "request-{$uploadId}"),
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => TenantOperationContext::newTraceparent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = (string) Str::ulid();
        $this->admin->table('face_analysis_runs')->insert([
            'id' => $runId,
            'family_space_id' => $familySpaceId,
            'media_upload_id' => $uploadId,
            'canonical_sha256' => hash('sha256', $uploadId),
            'contract_version' => '1',
            'provider' => 'synthetic',
            'model_identifier' => $model,
            'model_weight_checksum' => str_repeat('c', 64),
            'config_hash' => str_repeat('d', 64),
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

    private function binary(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            $this->assertNotFalse($contents);

            return $contents;
        }
        $this->assertIsString($value);

        return $value;
    }
}
