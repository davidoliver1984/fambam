<?php

namespace Tests\Feature;

use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Services\FamilyExportManager;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FamilyExportPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    private FamilyExportPostgresStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Family export generation requires runtime and administrative PostgreSQL connections.');
        }
        $this->admin = DB::connection('pgsql_admin');
        $this->admin->unprepared('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
        $this->storage = new FamilyExportPostgresStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::purge('pgsql_admin');
        }
        parent::tearDown();
    }

    public function test_collection_export_reference_is_tenant_consistent_and_cancelled_exports_remain_due(): void
    {
        $ownerId = (int) $this->admin->table('users')->insertGetId([
            'name' => 'Collection Owner', 'email' => 'collection-owner@example.test',
            'password' => 'not-used', 'timezone' => 'Europe/London',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $firstFamily = (string) Str::ulid();
        $otherFamily = (string) Str::ulid();
        foreach ([$firstFamily, $otherFamily] as $id) {
            $this->admin->transaction(function () use ($id, $ownerId): void {
                $this->admin->table('family_spaces')->insert([
                    'id' => $id, 'slug' => strtolower($id), 'name' => 'Export tenant',
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->admin->table('family_space_memberships')->insert([
                    'id' => (string) Str::ulid(), 'family_space_id' => $id,
                    'user_id' => $ownerId, 'role' => 'owner', 'state' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }
        $collectionId = (string) Str::ulid();
        $this->admin->table('collections')->insert([
            'id' => $collectionId, 'family_space_id' => $firstFamily,
            'owner_user_id' => $ownerId, 'name' => 'Selected Photos',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->admin->table('family_exports')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $otherFamily,
                'requested_by' => $ownerId, 'scope' => 'collection',
                'collection_id' => $collectionId, 'state' => 'pending',
                'object_key' => "families/{$otherFamily}/family-exports/cross-tenant.zip",
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('A Collection from another Family Space must not be referenced.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }

        $albumId = (string) Str::ulid();
        $this->admin->table('albums')->insert([
            'id' => $albumId, 'family_space_id' => $firstFamily,
            'created_by' => $ownerId, 'name' => 'Export Album',
            'visibility' => 'family_space', 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $this->admin->table('family_exports')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $otherFamily,
                'requested_by' => $ownerId, 'scope' => 'album',
                'album_id' => $albumId, 'state' => 'pending',
                'object_key' => "families/{$otherFamily}/family-exports/cross-album.zip",
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('An Album from another Family Space must not be referenced.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }

        $albumExportId = (string) Str::ulid();
        $this->admin->table('family_exports')->insert([
            'id' => $albumExportId, 'family_space_id' => $firstFamily,
            'requested_by' => $ownerId, 'scope' => 'album',
            'album_id' => $albumId, 'state' => 'failed',
            'object_key' => "families/{$firstFamily}/family-exports/{$albumExportId}.zip",
            'cancelled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('family_exports')->where('id', $albumExportId)
            ->update(['album_id' => null]);
        $this->admin->table('albums')->where('id', $albumId)->delete();
        $this->assertNull($this->admin->table('family_exports')->where('id', $albumExportId)->value('album_id'));

        $exportId = (string) Str::ulid();
        $this->admin->table('family_exports')->insert([
            'id' => $exportId, 'family_space_id' => $firstFamily,
            'requested_by' => $ownerId, 'scope' => 'collection',
            'collection_id' => $collectionId, 'state' => 'failed',
            'object_key' => "families/{$firstFamily}/family-exports/{$exportId}.zip",
            'cancelled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $due = $this->admin->select('SELECT family_export_id FROM app_due_family_exports()');
        $this->assertContains($exportId, array_map(
            fn (object $row): string => trim((string) $row->family_export_id), $due,
        ));

        $this->admin->table('family_exports')->where('id', $exportId)
            ->update(['collection_id' => null]);
        $this->admin->table('collections')->where('id', $collectionId)->delete();
        $afterDeletion = $this->admin->select('SELECT family_export_id FROM app_due_family_exports()');
        $this->assertContains($exportId, array_map(
            fn (object $row): string => trim((string) $row->family_export_id), $afterDeletion,
        ));
    }

    public function test_runtime_role_builds_and_persists_a_tenant_scoped_family_archive(): void
    {
        $ownerId = (int) $this->admin->table('users')->insertGetId([
            'name' => 'Archive Owner',
            'email' => 'archive-owner@example.test',
            'password' => 'not-used',
            'timezone' => 'Europe/London',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $familyId = (string) Str::ulid();
        $uploadId = (string) Str::ulid();
        $photoId = (string) Str::ulid();
        $exportId = (string) Str::ulid();
        $bytes = 'postgres-family-original';
        $originalKey = "families/{$familyId}/media/originals/{$uploadId}.jpg";
        $exportKey = "families/{$familyId}/family-exports/{$exportId}.zip";
        $this->storage->objects[$originalKey] = $bytes;

        $this->admin->transaction(function () use ($familyId, $ownerId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familyId,
                'slug' => 'postgres-export',
                'name' => 'PostgreSQL Export',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $familyId,
                'user_id' => $ownerId,
                'role' => 'owner',
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->admin->table('media_uploads')->insert([
            'id' => $uploadId,
            'family_space_id' => $familyId,
            'user_id' => $ownerId,
            'state' => 'ready',
            'staging_object_key' => "families/{$familyId}/media-staging/{$uploadId}/original",
            'original_object_key' => $originalKey,
            'original_sha256' => hash('sha256', $bytes),
            'client_filename' => 'postgres-family.jpg',
            'detected_mime_type' => 'image/jpeg',
            'idempotency_key' => "export-{$uploadId}",
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
            'visibility' => 'family_space',
            'caption' => 'Runtime-role archive proof',
            'do_not_resurface' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('family_exports')->insert([
            'id' => $exportId,
            'family_space_id' => $familyId,
            'requested_by' => $ownerId,
            'scope' => 'family_space_full',
            'state' => 'pending',
            'object_key' => $exportKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(FamilyExportManager::class)->generate(
            TenantOperationContext::forBackground($familyId, $ownerId),
            $exportId,
        );

        $export = $this->admin->table('family_exports')->where('id', $exportId)->first();
        $this->assertNotNull($export);
        $this->assertSame('ready', $export->state);
        $this->assertSame(1, $export->photo_count);
        $this->assertSame(hash('sha256', $this->storage->finalized[$exportKey]), $export->archive_sha256);
        $this->assertGreaterThan(0, $export->byte_size);
    }
}

class FamilyExportPostgresStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> */
    public array $finalized = [];

    public function authorizeSingleWrite(string $key, \DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        return new UploadAuthorization($key, [], CarbonImmutable::instance($expiresAt));
    }

    public function inspect(string $key): ?StoredObject
    {
        return null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        $bytes = file_get_contents($localPath);
        if ($bytes === false || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('Invalid family export finalization.');
        }
        $this->finalized[$key] = $bytes;
    }

    public function delete(string $key): void {}
}
