<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoThreadingPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Photo threading integrity requires administrative and runtime PostgreSQL connections.');
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

    public function test_photo_reply_context_constraints_and_forced_rls_are_database_enforced(): void
    {
        [$userId, $familyId, $firstPhoto, $firstAlbum] = $this->fixture('photo-thread-one');
        [, $otherFamilyId, $otherPhoto, $otherAlbum] = $this->fixture('photo-thread-two');
        $secondPhoto = $this->photo($familyId, $userId, 'second-photo');
        $secondAlbum = $this->album($familyId, $userId, $secondPhoto, 'second-album');
        $parentId = $this->comment($familyId, $firstPhoto, $firstAlbum, $userId);
        $replyId = $this->comment($familyId, $firstPhoto, $firstAlbum, $userId, $parentId);

        foreach ([
            [$familyId, $secondPhoto, $secondAlbum, $parentId],
            [$familyId, $firstPhoto, $secondAlbum, $parentId],
            [$otherFamilyId, $otherPhoto, $otherAlbum, $parentId],
        ] as [$replyFamily, $replyPhoto, $replyAlbum, $parent]) {
            $this->rejects(fn () => $this->comment($replyFamily, $replyPhoto, $replyAlbum, $userId, $parent));
        }
        $this->rejects(fn () => $this->admin->table('photo_comments')->where('id', $replyId)
            ->update(['parent_comment_id' => $replyId]));

        $flags = $this->admin->selectOne(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
            ['photo_comments'],
        );
        $this->assertTrue($flags->relrowsecurity);
        $this->assertTrue($flags->relforcerowsecurity);
        $visible = DB::transaction(function () use ($familyId, $userId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($familyId);

            return DB::table('photo_comments')->count();
        });
        $hidden = DB::transaction(function () use ($otherFamilyId, $userId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($otherFamilyId);

            return DB::table('photo_comments')->count();
        });
        $this->assertSame(2, $visible);
        $this->assertSame(0, $hidden);
    }

    /** @return array{int, string, string, string} */
    private function fixture(string $slug): array
    {
        $userId = (int) $this->admin->table('users')->insertGetId([
            'name' => $slug, 'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $familyId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familyId, $slug, $userId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familyId, 'slug' => $slug, 'name' => $slug, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'user_id' => $userId,
                'role' => 'owner', 'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $photoId = $this->photo($familyId, $userId, $slug);

        return [$userId, $familyId, $photoId, $this->album($familyId, $userId, $photoId, $slug)];
    }

    private function photo(string $familyId, int $userId, string $label): string
    {
        $uploadId = (string) Str::ulid();
        $photoId = (string) Str::ulid();
        $this->admin->table('media_uploads')->insert([
            'id' => $uploadId, 'family_space_id' => $familyId, 'user_id' => $userId, 'state' => 'ready',
            'staging_object_key' => "families/{$familyId}/media-staging/{$uploadId}/original",
            'client_filename' => "{$label}.jpg", 'idempotency_key' => "{$label}-{$uploadId}",
            'request_fingerprint' => hash('sha256', $uploadId), 'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('photos')->insert([
            'id' => $photoId, 'family_space_id' => $familyId, 'media_upload_id' => $uploadId,
            'created_by' => $userId, 'visibility' => 'family_space', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $photoId;
    }

    private function album(string $familyId, int $userId, string $photoId, string $label): string
    {
        $albumId = (string) Str::ulid();
        $this->admin->table('albums')->insert([
            'id' => $albumId, 'family_space_id' => $familyId, 'created_by' => $userId,
            'name' => $label, 'visibility' => 'family_space', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('album_photos')->insert([
            'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'album_id' => $albumId,
            'photo_id' => $photoId, 'position' => 1, 'added_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $albumId;
    }

    private function comment(string $familyId, string $photoId, string $albumId, int $userId, ?string $parentId = null): string
    {
        $id = (string) Str::ulid();
        $this->admin->table('photo_comments')->insert([
            'id' => $id, 'family_space_id' => $familyId, 'photo_id' => $photoId,
            'album_id' => $albumId, 'parent_comment_id' => $parentId, 'author_id' => $userId,
            'body' => json_encode(['schema_version' => 1, 'blocks' => []], JSON_THROW_ON_ERROR),
            'body_plain_text' => 'PostgreSQL comment', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted an invalid Photo reply relationship.');
        } catch (QueryException) {
        }
    }
}
