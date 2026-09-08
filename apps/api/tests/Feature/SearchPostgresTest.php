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
        $tagId = (string) Str::ulid();
        $this->admin->table('tags')->insert([
            'id' => $tagId,
            'family_space_id' => $familySpaceId,
            'label' => 'Winter',
            'normalized_label' => 'winter',
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('photo_tag')->insert([
            'photo_id' => $photoId,
            'tag_id' => $tagId,
            'family_space_id' => $familySpaceId,
            'added_by' => $ownerId,
            'created_at' => now(),
        ]);
        $this->admin->table('tags')->where('id', $tagId)->update([
            'label' => 'Renamed tag', 'normalized_label' => 'renamed tag',
        ]);
        $this->admin->table('photo_tag')->where('photo_id', $photoId)->delete();
        $stored = $this->admin->selectOne(
            'SELECT search_vector::text AS vector FROM photos WHERE id = ?',
            [$photoId],
        );
        $this->assertNotNull($stored);
        $this->assertSame($row->vector, $stored->vector);

        $owner = User::query()->findOrFail($ownerId);
        $this->actingAs($owner)
            ->getJson('/api/families/search-metadata/search?q=winter&group=photos')
            ->assertOk()
            ->assertJsonPath('data.photos.items.0.id', $photoId);
    }

    public function test_search_indexes_support_a_representative_multi_thousand_photo_family(): void
    {
        $expectedIndexes = [
            'albums_name_trgm_gin', 'albums_search_vector_gin',
            'events_location_trgm_gin', 'events_name_trgm_gin', 'events_search_vector_gin',
            'people_preferred_name_trgm_gin', 'people_search_vector_gin',
            'photo_stories_search_vector_gin', 'photos_caption_trgm_gin',
            'photos_historical_date_window_idx', 'photos_location_description_trgm_gin',
            'photos_search_vector_gin', 'tags_label_trgm_gin',
        ];
        $indexes = $this->admin->table('pg_indexes')->where('schemaname', 'public')
            ->whereIn('indexname', $expectedIndexes)->pluck('indexname')->sort()->values()->all();
        $this->assertSame($expectedIndexes, $indexes);

        [$ownerId, $familySpaceId] = $this->createOwnedFamily('search-performance');
        $this->admin->statement(<<<'SQL'
INSERT INTO media_uploads (
    id, family_space_id, user_id, state, staging_object_key, client_filename,
    idempotency_key, request_fingerprint, correlation_id, traceparent, created_at, updated_at
)
SELECT
    'm' || lpad(series::text, 25, '0'), ?, ?, 'ready',
    'families/performance/' || series::text, 'archive.jpg',
    'performance-' || series::text, md5(series::text) || md5('fingerprint-' || series::text),
    ('00000000-0000-0000-0000-' || lpad(series::text, 12, '0'))::uuid,
    '00-00000000000000000000000000000001-0000000000000001-01', now(), now()
FROM generate_series(1, 2500) AS series
SQL, [$familySpaceId, $ownerId]);
        $this->admin->statement(<<<'SQL'
INSERT INTO photos (
    id, family_space_id, media_upload_id, created_by, visibility, caption,
    historical_date, historical_date_precision, created_at, updated_at
)
SELECT
    'p' || lpad(series::text, 25, '0'), ?, 'm' || lpad(series::text, 25, '0'), ?,
    'family_space', CASE WHEN series % 125 = 0 THEN 'Needle memory' ELSE 'Archive memory' END,
    DATE '2000-01-01' + (series % 7300), 'exact', now(), now()
FROM generate_series(1, 2500) AS series
SQL, [$familySpaceId, $ownerId]);

        $explain = $this->admin->transaction(function () use ($familySpaceId): array {
            $this->admin->statement('ANALYZE photos');
            $this->admin->statement('SET LOCAL enable_seqscan = off');
            $row = $this->admin->selectOne(<<<'SQL'
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT id FROM photos
WHERE family_space_id = ?
  AND search_vector @@ websearch_to_tsquery('simple'::regconfig, 'needle')
ORDER BY id
LIMIT 12
SQL, [$familySpaceId]);
            $this->assertNotNull($row);

            return json_decode($row->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
        });
        $plan = json_encode($explain, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('photos_search_vector_gin', $plan);
        $this->assertLessThan(1000.0, (float) $explain[0]['Execution Time']);
    }

    public function test_postgres_generates_every_historical_date_window_boundary(): void
    {
        [$ownerId, $familySpaceId] = $this->createOwnedFamily('search-date-windows');
        foreach ([
            ['exact', '2024-02-29', '2024-02-29'],
            ['approximate', '2024-02-29', '2024-02-29'],
            ['month', '2024-02-01', '2024-02-29'],
            ['month', '2023-12-01', '2023-12-31'],
            ['year', '2024-01-01', '2024-12-31'],
            ['decade', '2020-01-01', '2029-12-31'],
        ] as $index => [$precision, $date, $expectedEnd]) {
            $uploadId = (string) Str::ulid();
            $this->createUpload($uploadId, $familySpaceId, $ownerId, "date-window-{$index}");
            $row = $this->admin->selectOne(<<<'SQL'
INSERT INTO photos (
    id, family_space_id, media_upload_id, created_by, visibility, caption,
    historical_date, historical_date_precision, created_at, updated_at
) VALUES (?, ?, ?, ?, 'family_space', 'Date window', ?::date, ?, now(), now())
RETURNING historical_date_window_end::text AS window_end
SQL, [(string) Str::ulid(), $familySpaceId, $uploadId, $ownerId, $date, $precision]);
            $this->assertNotNull($row);
            $this->assertSame($expectedEnd, $row->window_end);
        }
    }

    public function test_match_class_precedence_is_deterministic(): void
    {
        [$ownerId, $familySpaceId] = $this->createOwnedFamily('search-ranking');
        $photoIds = [];
        foreach ([
            ['Family archive', null],
            ['Summer memory', 'A family archive gathered together'],
            ['Family arcvive', null],
            ['Unrelated memory', null],
        ] as $index => [$caption, $description]) {
            $uploadId = (string) Str::ulid();
            $photoId = (string) Str::ulid();
            $photoIds[] = $photoId;
            $this->createUpload($uploadId, $familySpaceId, $ownerId, "ranking-{$index}");
            $this->admin->table('photos')->insert([
                'id' => $photoId,
                'family_space_id' => $familySpaceId,
                'media_upload_id' => $uploadId,
                'created_by' => $ownerId,
                'visibility' => 'family_space',
                'caption' => $caption,
                'description' => $description,
                'historical_date' => '2000-01-01',
                'historical_date_precision' => 'exact',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $tagId = (string) Str::ulid();
        $this->admin->table('tags')->insert([
            'id' => $tagId,
            'family_space_id' => $familySpaceId,
            'label' => 'Family archive',
            'normalized_label' => 'family archive',
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('photo_tag')->insert([
            'photo_id' => $photoIds[3],
            'tag_id' => $tagId,
            'family_space_id' => $familySpaceId,
            'added_by' => $ownerId,
            'created_at' => now(),
        ]);

        $owner = User::query()->findOrFail($ownerId);
        $response = $this->actingAs($owner)
            ->getJson('/api/families/search-ranking/search?q=family%20archive&group=photos&limit=10')
            ->assertOk();
        $items = $response->json('data.photos.items');
        $this->assertIsArray($items);
        $this->assertSame($photoIds, array_column($items, 'id'));
    }

    /** @return array{int, string} */
    private function createOwnedFamily(string $slug): array
    {
        $ownerId = (int) $this->admin->table('users')->insertGetId([
            'name' => 'Search Owner',
            'email' => "{$slug}@example.test",
            'password' => 'not-used-in-this-test',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $familySpaceId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familySpaceId, $ownerId, $slug): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familySpaceId,
                'slug' => $slug,
                'name' => 'Search Test',
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

        return [$ownerId, $familySpaceId];
    }

    private function createUpload(string $id, string $familySpaceId, int $ownerId, string $suffix): void
    {
        $this->admin->table('media_uploads')->insert([
            'id' => $id,
            'family_space_id' => $familySpaceId,
            'user_id' => $ownerId,
            'state' => 'ready',
            'staging_object_key' => "families/{$familySpaceId}/{$suffix}",
            'client_filename' => 'archive.jpg',
            'idempotency_key' => $suffix,
            'request_fingerprint' => hash('sha256', $suffix),
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
