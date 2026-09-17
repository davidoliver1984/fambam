<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Collection integrity requires administrative and runtime PostgreSQL connections.');
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

    public function test_collection_membership_constraints_and_forced_tenant_isolation(): void
    {
        [$ownerId, $familyId, $photoId, $secondPhotoId] = $this->fixture('collection-pg-one');
        [, $otherFamilyId, $otherPhotoId] = $this->fixture('collection-pg-two');
        $collectionId = (string) Str::ulid();
        $this->admin->table('collections')->insert(['id' => $collectionId,
            'family_space_id' => $familyId, 'owner_user_id' => $ownerId,
            'name' => 'Private selections', 'created_at' => now(), 'updated_at' => now()]);
        $firstLink = ['id' => (string) Str::ulid(), 'family_space_id' => $familyId,
            'collection_id' => $collectionId, 'photo_id' => $photoId, 'position' => 1];
        $this->admin->table('collection_photos')->insert($firstLink);
        $this->rejects(fn () => $this->admin->table('collection_photos')->insert([
            ...$firstLink, 'id' => (string) Str::ulid(), 'position' => 2,
        ]));
        $this->rejects(fn () => $this->admin->table('collection_photos')->insert([
            ...$firstLink, 'id' => (string) Str::ulid(), 'photo_id' => $otherPhotoId, 'position' => 2,
        ]));
        $this->rejects(fn () => $this->admin->table('collection_photos')->insert([
            ...$firstLink, 'id' => (string) Str::ulid(), 'family_space_id' => $otherFamilyId,
            'photo_id' => $otherPhotoId, 'position' => 2,
        ]));
        $this->rejects(fn () => $this->admin->table('collection_photos')->insert([
            ...$firstLink, 'id' => (string) Str::ulid(), 'photo_id' => $secondPhotoId, 'position' => 1,
        ]));
        $this->rejects(fn () => $this->admin->table('collection_photos')->insert([
            ...$firstLink, 'id' => (string) Str::ulid(), 'photo_id' => $secondPhotoId, 'position' => 0,
        ]));
        foreach (['collections', 'collection_photos'] as $table) {
            $flags = $this->admin->selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
            $this->assertTrue($flags->relrowsecurity);
            $this->assertTrue($flags->relforcerowsecurity);
        }
        $visible = DB::transaction(function () use ($ownerId, $familyId): int {
            app(DatabaseTenantContext::class)->establishUser($ownerId);
            app(DatabaseTenantContext::class)->establishFamilySpace($familyId);

            return DB::table('collection_photos')->count();
        });
        $hidden = DB::transaction(function () use ($ownerId, $otherFamilyId): int {
            app(DatabaseTenantContext::class)->establishUser($ownerId);
            app(DatabaseTenantContext::class)->establishFamilySpace($otherFamilyId);

            return DB::table('collection_photos')->count();
        });
        $this->assertSame(1, $visible);
        $this->assertSame(0, $hidden);
        $this->assertSame(1, DB::transaction(function () use ($ownerId, $familyId): int {
            app(DatabaseTenantContext::class)->establishUser($ownerId);
            app(DatabaseTenantContext::class)->establishFamilySpace($familyId);

            return DB::table('collections')->count();
        }));
        $this->assertSame(0, DB::transaction(function () use ($ownerId, $otherFamilyId): int {
            app(DatabaseTenantContext::class)->establishUser($ownerId);
            app(DatabaseTenantContext::class)->establishFamilySpace($otherFamilyId);

            return DB::table('collections')->count();
        }));
        $this->admin->table('collections')->where('id', $collectionId)->delete();
        $this->assertSame(0, $this->admin->table('collection_photos')->where('collection_id', $collectionId)->count());
        $this->assertSame(1, $this->admin->table('photos')->where('id', $photoId)->count());
    }

    /** @return array{int, string, string, string} */
    private function fixture(string $slug): array
    {
        $ownerId = (int) $this->admin->table('users')->insertGetId(['name' => $slug,
            'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now()]);
        $familyId = (string) Str::ulid();
        $this->admin->transaction(function () use ($ownerId, $familyId, $slug): void {
            $this->admin->table('family_spaces')->insert(['id' => $familyId, 'slug' => $slug,
                'name' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->admin->table('family_space_memberships')->insert(['id' => (string) Str::ulid(),
                'family_space_id' => $familyId, 'user_id' => $ownerId, 'role' => 'owner',
                'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        });
        $photoIds = [];
        foreach (range(1, 2) as $index) {
            $uploadId = (string) Str::ulid();
            $photoId = (string) Str::ulid();
            $this->admin->table('media_uploads')->insert(['id' => $uploadId,
                'family_space_id' => $familyId, 'user_id' => $ownerId, 'state' => 'ready',
                'staging_object_key' => "families/{$familyId}/media-staging/{$uploadId}/original",
                'client_filename' => "collection-{$index}.jpg", 'idempotency_key' => $uploadId,
                'request_fingerprint' => hash('sha256', $uploadId),
                'correlation_id' => (string) Str::uuid(),
                'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
                'created_at' => now(), 'updated_at' => now()]);
            $this->admin->table('photos')->insert(['id' => $photoId, 'family_space_id' => $familyId,
                'media_upload_id' => $uploadId, 'created_by' => $ownerId,
                'created_at' => now(), 'updated_at' => now()]);
            $photoIds[] = $photoId;
        }

        return [$ownerId, $familyId, $photoIds[0], $photoIds[1]];
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted an invalid Collection relationship or position.');
        } catch (QueryException) {
        }
    }
}
