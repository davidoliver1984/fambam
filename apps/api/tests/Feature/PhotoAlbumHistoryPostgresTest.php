<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoAlbumHistoryPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Photo Album history requires administrative and runtime PostgreSQL connections.');
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

    public function test_bounded_audit_function_is_tenant_scoped_without_raw_audit_select_access(): void
    {
        [$actor, $family, $photo, $album] = $this->fixture('history-pg-one');
        [, $otherFamily, $otherPhoto, $otherAlbum] = $this->fixture('history-pg-two');
        $this->audit($family, $actor, $photo, $album);
        $this->audit($otherFamily, $actor, $otherPhoto, $otherAlbum);

        DB::transaction(function () use ($actor, $family, $photo, $album, $otherFamily, $otherPhoto): void {
            app(DatabaseTenantContext::class)->establishUser($actor);
            app(DatabaseTenantContext::class)->establishFamilySpace($family);

            $rows = DB::select('SELECT * FROM app_photo_album_history_events(?, ?)', [$family, $photo]);
            $this->assertCount(1, $rows);
            $this->assertSame('added', $rows[0]->event_type);
            $this->assertSame($album, $rows[0]->album_id);
            $this->assertSame([], DB::select(
                'SELECT * FROM app_photo_album_history_events(?, ?)',
                [$otherFamily, $otherPhoto],
            ));
        });

        $privileges = DB::selectOne(<<<'SQL'
SELECT
    has_table_privilege(current_user, 'audit_events', 'SELECT') AS can_select_audit,
    has_function_privilege(current_user, 'app_photo_album_history_events(text,text)', 'EXECUTE') AS can_execute_history
SQL);
        $this->assertFalse($privileges->can_select_audit);
        $this->assertTrue($privileges->can_execute_history);
    }

    /** @return array{int, string, string, string} */
    private function fixture(string $slug): array
    {
        $actor = (int) $this->admin->table('users')->insertGetId([
            'name' => $slug,
            'email' => "{$slug}@example.test",
            'password' => 'not-used',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $family = (string) Str::ulid();
        $upload = (string) Str::ulid();
        $photo = (string) Str::ulid();
        $album = (string) Str::ulid();
        $this->admin->transaction(function () use ($family, $slug, $actor): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $family,
                'slug' => $slug,
                'name' => $slug,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $family,
                'user_id' => $actor,
                'role' => 'owner',
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->admin->table('media_uploads')->insert([
            'id' => $upload,
            'family_space_id' => $family,
            'user_id' => $actor,
            'state' => 'ready',
            'staging_object_key' => "staging/{$upload}",
            'client_filename' => 'scan.jpg',
            'idempotency_key' => $upload,
            'request_fingerprint' => hash('sha256', $upload),
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.str_repeat('1', 32).'-'.str_repeat('2', 16).'-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('photos')->insert([
            'id' => $photo,
            'family_space_id' => $family,
            'media_upload_id' => $upload,
            'created_by' => $actor,
            'visibility' => 'family_space',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('albums')->insert([
            'id' => $album,
            'family_space_id' => $family,
            'created_by' => $actor,
            'name' => $slug,
            'visibility' => 'family_space',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $family, $photo, $album];
    }

    private function audit(string $family, int $actor, string $photo, string $album): void
    {
        $this->admin->table('audit_events')->insert([
            'family_space_id' => $family,
            'actor_user_id' => $actor,
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.str_repeat('3', 32).'-'.str_repeat('4', 16).'-01',
            'action' => 'album.photo_added',
            'subject_type' => 'App\\Models\\AlbumPhoto',
            'subject_id' => (string) Str::ulid(),
            'metadata' => json_encode(['album_id' => $album, 'photo_id' => $photo]),
            'created_at' => now(),
        ]);
    }
}
