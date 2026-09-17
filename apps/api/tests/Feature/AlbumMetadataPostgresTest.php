<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlbumMetadataPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Album metadata integrity requires administrative and runtime PostgreSQL connections.');
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

    public function test_album_metadata_fks_checks_and_forced_rls(): void
    {
        [$userId, $familyId, $albumId, $photoId, $personId, $tagId] = $this->fixture('album-pg-one');
        [, $otherFamilyId, , $otherPhotoId, $otherPersonId, $otherTagId] = $this->fixture('album-pg-two');

        $this->admin->table('albums')->where('id', $albumId)->update([
            'starts_on' => '2000-01-01', 'ends_on' => '2000-12-31',
            'cover_photo_id' => $photoId, 'cover_focal_x' => 0.5, 'cover_focal_y' => 0.5,
        ]);
        $this->admin->table('album_people')->insert(['id' => (string) Str::ulid(),
            'family_space_id' => $familyId, 'album_id' => $albumId,
            'person_id' => $personId, 'added_by' => $userId]);
        $this->admin->table('album_tag')->insert(['family_space_id' => $familyId,
            'album_id' => $albumId, 'tag_id' => $tagId, 'added_by' => $userId]);

        $this->rejects(fn () => $this->admin->table('albums')->where('id', $albumId)
            ->update(['cover_photo_id' => $otherPhotoId]));
        $this->rejects(fn () => $this->admin->table('albums')->where('id', $albumId)
            ->update(['ends_on' => '1999-12-31']));
        $this->rejects(fn () => $this->admin->table('albums')->where('id', $albumId)
            ->update(['cover_focal_x' => 1.5]));
        $this->rejects(fn () => $this->admin->table('album_people')->insert([
            'id' => (string) Str::ulid(), 'family_space_id' => $familyId,
            'album_id' => $albumId, 'person_id' => $otherPersonId,
        ]));
        $this->rejects(fn () => $this->admin->table('album_tag')->insert([
            'family_space_id' => $familyId, 'album_id' => $albumId, 'tag_id' => $otherTagId,
        ]));

        foreach (['album_people', 'album_tag'] as $table) {
            $flags = $this->admin->selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
            $this->assertTrue($flags->relrowsecurity);
            $this->assertTrue($flags->relforcerowsecurity);
        }
        $visible = DB::transaction(function () use ($userId, $familyId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($familyId);

            return DB::table('album_people')->count();
        });
        $hidden = DB::transaction(function () use ($userId, $otherFamilyId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($otherFamilyId);

            return DB::table('album_people')->count();
        });
        $this->assertSame(1, $visible);
        $this->assertSame(0, $hidden);
    }

    /** @return array{int, string, string, string, string, string} */
    private function fixture(string $slug): array
    {
        $userId = (int) $this->admin->table('users')->insertGetId([
            'name' => $slug, 'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $familyId = (string) Str::ulid();
        $albumId = (string) Str::ulid();
        $uploadId = (string) Str::ulid();
        $photoId = (string) Str::ulid();
        $personId = (string) Str::ulid();
        $tagId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familyId, $slug, $userId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familyId, 'slug' => $slug, 'name' => $slug, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId,
                'user_id' => $userId, 'role' => 'owner', 'state' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->admin->table('albums')->insert(['id' => $albumId,
            'family_space_id' => $familyId, 'created_by' => $userId, 'name' => $slug,
            'created_at' => now(), 'updated_at' => now()]);
        $this->admin->table('media_uploads')->insert(['id' => $uploadId,
            'family_space_id' => $familyId, 'user_id' => $userId, 'state' => 'ready',
            'staging_object_key' => "families/{$familyId}/media-staging/{$uploadId}/original",
            'client_filename' => 'cover.jpg', 'idempotency_key' => $uploadId,
            'request_fingerprint' => hash('sha256', $uploadId), 'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('photos')->insert(['id' => $photoId,
            'family_space_id' => $familyId, 'media_upload_id' => $uploadId,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $this->admin->table('people')->insert(['id' => $personId,
            'family_space_id' => $familyId, 'preferred_name' => $slug,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $this->admin->table('tags')->insert(['id' => $tagId,
            'family_space_id' => $familyId, 'label' => $slug, 'normalized_label' => $slug,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        return [$userId, $familyId, $albumId, $photoId, $personId, $tagId];
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted an invalid Album relationship or value.');
        } catch (QueryException) {
        }
    }
}
