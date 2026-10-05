<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SettingsOverviewPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Settings Overview RLS verification requires runtime and administrative PostgreSQL connections.');
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

    public function test_runtime_role_aggregates_cannot_observe_hidden_or_cross_family_archive_data(): void
    {
        [$familyId, $familySlug, $ownerId] = $this->family('settings-overview-pg');
        [$otherFamilyId, , $otherOwnerId] = $this->family('other-settings-overview-pg');
        $viewerId = $this->user('Overview member');
        $this->membership($familyId, $viewerId, 'member');

        $firstPerson = $this->person($familyId, 'Visible first');
        $secondPerson = $this->person($familyId, 'Visible second');
        $foreignPerson = $this->person($otherFamilyId, 'Foreign Person');
        $this->relationship($familyId, $ownerId, $firstPerson, $secondPerson);
        $this->relationship($otherFamilyId, $otherOwnerId, $foreignPerson, $this->person($otherFamilyId, 'Foreign second'));

        [$visiblePhoto, $visibleUpload] = $this->photo($familyId, $ownerId, 'family_space', true);
        [$privatePhoto, $privateUpload] = $this->photo($familyId, $ownerId, 'private', true);
        [$foreignPhoto, $foreignUpload] = $this->photo($otherFamilyId, $otherOwnerId, 'family_space', true);
        $visibleObservation = $this->observation($familyId, $visibleUpload);
        $privateObservation = $this->observation($familyId, $privateUpload);
        $foreignObservation = $this->observation($otherFamilyId, $foreignUpload);
        $this->approvedIdentity($familyId, $visibleObservation, $firstPerson);
        $this->approvedIdentity($familyId, $privateObservation, $firstPerson);
        $this->approvedIdentity($otherFamilyId, $foreignObservation, $foreignPerson);

        $visibleAlbum = $this->album($familyId, $ownerId, 'family_space');
        $this->album($familyId, $ownerId, 'private');
        $this->album($otherFamilyId, $otherOwnerId, 'family_space');
        $this->story($familyId, $ownerId, photoId: $visiblePhoto);
        $this->story($familyId, $ownerId, photoId: $privatePhoto);
        $this->story($familyId, $ownerId, albumId: $visibleAlbum);
        $this->story($otherFamilyId, $otherOwnerId, photoId: $foreignPhoto);

        /** @var User $viewer */
        $viewer = User::on('pgsql_admin')->findOrFail($viewerId);
        $response = $this->actingAs($viewer)
            ->getJson("/api/families/{$familySlug}/settings/overview")
            ->assertOk();

        $response->assertExactJson(['data' => [
            'counts' => ['people' => 2, 'photos' => 1, 'albums' => 1, 'stories' => 2],
            'archive_health' => [
                'photos_dated' => ['numerator' => 1, 'denominator' => 1, 'percentage' => 100],
                'faces_identified' => ['numerator' => 1, 'denominator' => 1, 'percentage' => 100],
                'people_connected' => ['numerator' => 2, 'denominator' => 2, 'percentage' => 100],
            ],
        ]]);

        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame(config('database.runtime_role'), $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    /** @return array{string, string, int} */
    private function family(string $slug): array
    {
        $ownerId = $this->user("{$slug} owner");
        $familyId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familyId, $ownerId, $slug): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familyId,
                'slug' => $slug,
                'name' => $slug,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->membership($familyId, $ownerId, 'owner');
        });

        return [$familyId, $slug, $ownerId];
    }

    private function user(string $name): int
    {
        return (int) $this->admin->table('users')->insertGetId([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'password' => 'not-used',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function membership(string $familyId, int $userId, string $role): void
    {
        $this->admin->table('family_space_memberships')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familyId,
            'user_id' => $userId,
            'role' => $role,
            'state' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function person(string $familyId, string $name): string
    {
        $id = (string) Str::ulid();
        $this->admin->table('people')->insert([
            'id' => $id,
            'family_space_id' => $familyId,
            'preferred_name' => $name,
            'alternate_names' => '[]',
            'identity_status' => 'confirmed',
            'birth_date_precision' => 'unknown',
            'is_deceased' => false,
            'death_date_precision' => 'unknown',
            'recognition_allowed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function relationship(string $familyId, int $actorId, string $subjectId, string $relatedId): void
    {
        $this->admin->table('person_relationships')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familyId,
            'subject_person_id' => $subjectId,
            'related_person_id' => $relatedId,
            'type' => 'partner_of',
            'status' => 'confirmed',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{string, string} */
    private function photo(string $familyId, int $ownerId, string $visibility, bool $dated): array
    {
        $uploadId = (string) Str::ulid();
        $photoId = (string) Str::ulid();
        $checksum = str_repeat('a', 64);
        $this->admin->table('media_uploads')->insert([
            'id' => $uploadId,
            'family_space_id' => $familyId,
            'user_id' => $ownerId,
            'state' => 'ready',
            'staging_object_key' => "families/{$familyId}/media-staging/{$uploadId}/original",
            'canonical_object_key' => "families/{$familyId}/media/{$uploadId}/canonical.webp",
            'canonical_mime_type' => 'image/webp',
            'canonical_sha256' => $checksum,
            'client_filename' => "{$photoId}.jpg",
            'idempotency_key' => "settings-overview-{$uploadId}",
            'request_fingerprint' => hash('sha256', $uploadId),
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('photos')->insert([
            'id' => $photoId,
            'family_space_id' => $familyId,
            'media_upload_id' => $uploadId,
            'created_by' => $ownerId,
            'visibility' => $visibility,
            'historical_date' => $dated ? '1984-06-01' : null,
            'historical_date_precision' => $dated ? 'month' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$photoId, $uploadId];
    }

    private function observation(string $familyId, string $uploadId): string
    {
        $identity = config('image-analysis.identity');
        $runId = (string) Str::ulid();
        $observationId = (string) Str::ulid();
        $this->admin->table('face_analysis_runs')->insert([
            'id' => $runId,
            'family_space_id' => $familyId,
            'media_upload_id' => $uploadId,
            'canonical_sha256' => str_repeat('a', 64),
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
        $this->admin->table('face_observations')->insert([
            'id' => $observationId,
            'family_space_id' => $familyId,
            'face_analysis_run_id' => $runId,
            'face_index' => 0,
            'bounds_x' => 1,
            'bounds_y' => 1,
            'bounds_width' => 10,
            'bounds_height' => 10,
            'landmarks' => '[]',
            'landmark_scheme' => '5-point',
            'detection_confidence' => 1,
            'embedding' => DB::raw("decode('00000000', 'hex')"),
            'embedding_dimension' => 1,
            'embedding_dtype' => 'float32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $observationId;
    }

    private function approvedIdentity(string $familyId, string $observationId, string $personId): void
    {
        $this->admin->table('face_identity_assignments')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familyId,
            'face_observation_id' => $observationId,
            'person_id' => $personId,
            'proposal_source' => 'human',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function album(string $familyId, int $ownerId, string $visibility): string
    {
        $id = (string) Str::ulid();
        $this->admin->table('albums')->insert([
            'id' => $id,
            'family_space_id' => $familyId,
            'created_by' => $ownerId,
            'name' => "{$visibility} Album",
            'visibility' => $visibility,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function story(
        string $familyId,
        int $authorId,
        ?string $photoId = null,
        ?string $albumId = null,
    ): void {
        $this->admin->table('stories')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familyId,
            'author_id' => $authorId,
            'photo_id' => $photoId,
            'album_id' => $albumId,
            'body' => json_encode(['schema_version' => 1, 'blocks' => []], JSON_THROW_ON_ERROR),
            'body_plain_text' => 'PostgreSQL Settings Overview Story',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
