<?php

namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
        $this->assertNull($this->admin->table('collections')->where('id', $collectionId)->value('purpose'));
        $this->rejects(fn () => $this->admin->table('collections')->where('id', $collectionId)
            ->update(['purpose' => 'unsupported']));
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

    public function test_collection_index_read_model_runs_under_the_runtime_role_without_owner_leakage(): void
    {
        Carbon::setTestNow('2026-10-04 09:00:00');
        [$ownerId, $familyId, $firstPhotoId, $secondPhotoId] = $this->fixture('collection-pg-index');
        $firstUploadId = $this->admin->table('photos')->where('id', $firstPhotoId)
            ->value('media_upload_id');
        $secondUploadId = $this->admin->table('photos')->where('id', $secondPhotoId)
            ->value('media_upload_id');
        $actor = new User;
        $actor->forceFill(['id' => $ownerId, 'name' => 'Runtime owner']);
        $actor->exists = true;
        $base = '/api/families/collection-pg-index/collections';

        $this->assertSame(config('database.runtime_role'), DB::selectOne('SELECT current_user')->current_user);
        $collectionId = $this->actingAs($actor)->postJson($base, [
            'name' => 'Runtime selections', 'purpose' => 'prints',
        ])
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'prints')
            ->assertJsonPath('data.photo_count', 0)
            ->assertJsonPath('data.preview_photo', null)
            ->json('data.id');
        Carbon::setTestNow('2026-10-04 09:00:01');
        $this->actingAs($actor)->postJson("{$base}/{$collectionId}/photos", [
            'photo_id' => $firstPhotoId,
        ])->assertCreated()
            ->assertJsonPath('data.photo_count', 1)
            ->assertJsonPath('data.preview_photo.media_upload_id', $firstUploadId);
        Carbon::setTestNow('2026-10-04 09:00:02');
        $this->actingAs($actor)->postJson("{$base}/{$collectionId}/photos/batch", [
            'photo_ids' => [$secondPhotoId],
        ])->assertCreated()->assertJsonPath('added', 1);
        $beforeReorder = $this->actingAs($actor)->getJson($base)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $collectionId)
            ->assertJsonPath('data.0.photo_count', 2)
            ->assertJsonPath('data.0.preview_photo.photo_id', $firstPhotoId)
            ->assertJsonPath('data.0.preview_photo.media_upload_id', $firstUploadId)
            ->json('data.0.updated_at');
        $this->actingAs($actor)->getJson("{$base}?purpose=prints&q=runtime")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $collectionId);
        $this->actingAs($actor)->getJson("{$base}?purpose=calendar")
            ->assertOk()->assertJsonCount(0, 'data');
        Carbon::setTestNow('2026-10-04 09:00:03');
        $this->actingAs($actor)->putJson("{$base}/{$collectionId}/order", [
            'photo_ids' => [$secondPhotoId, $firstPhotoId],
        ])->assertOk();
        $afterReorder = $this->actingAs($actor)->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.0.preview_photo.photo_id', $secondPhotoId)
            ->assertJsonPath('data.0.preview_photo.media_upload_id', $secondUploadId)
            ->json('data.0.updated_at');
        $this->assertNotSame($beforeReorder, $afterReorder);
        Carbon::setTestNow('2026-10-04 09:00:04');
        $this->actingAs($actor)->deleteJson("{$base}/{$collectionId}/photos/{$secondPhotoId}")
            ->assertNoContent();
        $this->actingAs($actor)->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.0.photo_count', 1)
            ->assertJsonPath('data.0.preview_photo.photo_id', $firstPhotoId);

        $otherUserId = (int) $this->admin->table('users')->insertGetId([
            'name' => 'Other owner',
            'email' => 'collection-pg-index-other@example.test',
            'password' => 'not-used',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('family_space_memberships')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familyId,
            'user_id' => $otherUserId,
            'role' => 'member',
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherActor = new User;
        $otherActor->forceFill(['id' => $otherUserId, 'name' => 'Other owner']);
        $otherActor->exists = true;
        $this->actingAs($otherActor)->getJson($base)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($otherActor)->getJson("{$base}?purpose=prints&collection_id={$collectionId}")
            ->assertOk()->assertJsonCount(0, 'data');
        Carbon::setTestNow();
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
