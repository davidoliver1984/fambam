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
