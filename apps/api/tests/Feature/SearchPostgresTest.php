<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Search metadata tests require runtime and administrative PostgreSQL connections.');
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

    public function test_search_extensions_vectors_and_calendar_safe_date_windows_are_database_generated(): void
    {
        $this->assertSame('1', (string) DB::scalar(
            "SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'",
        ));
        $ownerId = (int) $this->admin->table('users')->insertGetId([
            'name' => 'Search Owner',
            'email' => 'search-owner@example.test',
            'password' => 'not-used-in-this-test',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $familySpaceId = (string) Str::ulid();
        $uploadId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familySpaceId, $ownerId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familySpaceId,
                'slug' => 'search-metadata',
                'name' => 'Search Metadata',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $familySpaceId,
                'user_id' => $ownerId,
                'role' => 'owner',
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->admin->table('media_uploads')->insert([
            'id' => $uploadId,
            'family_space_id' => $familySpaceId,
            'user_id' => $ownerId,
            'state' => 'ready',
            'staging_object_key' => "families/{$familySpaceId}/media-staging/{$uploadId}/original",
            'client_filename' => 'winter.jpg',
            'idempotency_key' => "search-{$uploadId}",
            'request_fingerprint' => hash('sha256', $uploadId),
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $photoId = (string) Str::ulid();
        $row = $this->admin->selectOne(<<<'SQL'
INSERT INTO photos (
    id, family_space_id, media_upload_id, created_by, visibility, caption,
    historical_date, historical_date_precision, created_at, updated_at
) VALUES (?, ?, ?, ?, 'family_space', 'Winter archive', DATE '1980-01-01', 'decade', now(), now())
RETURNING search_vector::text AS vector, historical_date_window_end::text AS window_end
SQL, [$photoId, $familySpaceId, $uploadId, $ownerId]);
        $this->assertNotNull($row);
        $this->assertStringContainsString('winter', $row->vector);
        $this->assertSame('1989-12-31', $row->window_end);

        $owner = User::query()->findOrFail($ownerId);
        $this->actingAs($owner)
            ->getJson('/api/families/search-metadata/search?q=winter&group=photos')
            ->assertOk()
            ->assertJsonPath('data.photos.items.0.id', $photoId);
    }
}
