<?php

namespace Tests\Feature;

use App\Media\MediaObjectStorage;
use App\Services\ExpiredPhotoEditPreviewCleaner;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoVersionPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('PhotoVersion constraints require PostgreSQL admin and runtime connections.');
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

    public function test_same_photo_active_version_restrict_and_forced_rls(): void
    {
        [$actor, $family, $first] = $this->fixture('version-one');
        [, , $second] = $this->fixture('version-two', $actor, $family);
        [, $otherFamily, $otherPhoto] = $this->fixture('version-other');
        $version = (string) Str::ulid();
        $this->admin->table('photo_versions')->insert(['id' => $version,
            'family_space_id' => $family, 'photo_id' => $first,
            'edit_recipe' => json_encode(['schema_version' => 1]),
            'derived_object_key' => 'families/first/versions/one.webp']);
        $this->rejects(fn () => $this->admin->table('photos')->where('id', $second)
            ->update(['active_photo_version_id' => $version]));
        $this->rejects(fn () => $this->admin->table('photos')->where('id', $otherPhoto)
            ->update(['active_photo_version_id' => $version]));
        $this->rejects(fn () => $this->admin->table('photo_versions')->insert([
            'id' => (string) Str::ulid(), 'family_space_id' => $otherFamily,
            'photo_id' => $first, 'edit_recipe' => json_encode(['schema_version' => 1]),
            'derived_object_key' => 'invalid.webp']));
        $this->admin->table('photos')->where('id', $first)->update(['active_photo_version_id' => $version]);
        $this->rejects(fn () => $this->admin->table('photo_versions')->where('id', $version)->delete());
        $this->admin->table('photos')->where('id', $first)->update(['deleted_at' => now()]);
        $this->assertSame($version, trim((string) $this->admin->table('photos')->where('id', $first)
            ->value('active_photo_version_id')));
        $this->admin->table('photos')->where('id', $first)->update(['deleted_at' => null]);
        $this->admin->table('photos')->where('id', $first)->update(['active_photo_version_id' => null]);
        $this->admin->table('photo_versions')->where('id', $version)->delete();
        $this->assertSame(0, $this->admin->table('photo_versions')->count());
        foreach (['photo_versions', 'photo_edit_previews'] as $table) {
            $flags = $this->admin->selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
            $this->assertTrue($flags->relrowsecurity);
            $this->assertTrue($flags->relforcerowsecurity);
        }
        $previewId = (string) Str::ulid();
        $this->admin->table('photo_edit_previews')->insert(['id' => $previewId,
            'family_space_id' => $family, 'photo_id' => $first, 'requested_by' => $actor,
            'edit_recipe' => json_encode(['schema_version' => 1]), 'object_key' => 'preview.webp',
            'expires_at' => now()->subMinute()]);
        $this->assertSame(1, $this->tenantCount('photo_edit_previews', $actor, $family));
        $this->assertSame(0, $this->tenantCount('photo_edit_previews', $actor, $otherFamily));
        $due = DB::select('SELECT * FROM app_due_photo_edit_previews()');
        $this->assertSame($previewId, trim((string) $due[0]->preview_id));
        $storage = $this->createMock(MediaObjectStorage::class);
        $storage->expects($this->once())->method('delete')->with('preview.webp');
        $this->app->instance(MediaObjectStorage::class, $storage);
        app(ExpiredPhotoEditPreviewCleaner::class)->purge(
            TenantOperationContext::forBackground($family, $actor), $previewId);
        $this->assertSame(0, $this->admin->table('photo_edit_previews')->count());
    }

    /** @return array{int,string,string} */
    private function fixture(string $slug, ?int $existingUser = null, ?string $existingFamily = null): array
    {
        $user = $existingUser ?? (int) $this->admin->table('users')->insertGetId([
            'name' => $slug, 'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now()]);
        $family = $existingFamily ?? (string) Str::ulid();
        if ($existingFamily === null) {
            $this->admin->transaction(function () use ($family, $slug, $user): void {
                $this->admin->table('family_spaces')->insert(['id' => $family, 'slug' => $slug,
                    'name' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                $this->admin->table('family_space_memberships')->insert(['id' => (string) Str::ulid(),
                    'family_space_id' => $family, 'user_id' => $user, 'role' => 'owner', 'state' => 'active',
                    'created_at' => now(), 'updated_at' => now()]);
            });
        }
        $upload = (string) Str::ulid();
        $photo = (string) Str::ulid();
        $this->admin->table('media_uploads')->insert(['id' => $upload, 'family_space_id' => $family,
            'user_id' => $user, 'state' => 'ready', 'staging_object_key' => "staging/{$upload}",
            'client_filename' => 'scan.jpg', 'idempotency_key' => $upload,
            'request_fingerprint' => hash('sha256', $upload), 'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.str_repeat('1', 32).'-'.str_repeat('2', 16).'-01',
            'created_at' => now(), 'updated_at' => now()]);
        $this->admin->table('photos')->insert(['id' => $photo, 'family_space_id' => $family,
            'media_upload_id' => $upload, 'created_by' => $user, 'visibility' => 'family_space',
            'created_at' => now(), 'updated_at' => now()]);

        return [$user, $family, $photo];
    }

    private function tenantCount(string $table, int $user, string $family): int
    {
        return DB::transaction(function () use ($table, $user, $family): int {
            app(DatabaseTenantContext::class)->establishUser($user);
            app(DatabaseTenantContext::class)->establishFamilySpace($family);

            return DB::table($table)->count();
        });
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('PostgreSQL accepted an invalid PhotoVersion operation.');
        } catch (QueryException) {
        }
    }
}
